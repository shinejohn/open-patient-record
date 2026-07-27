<?php

declare(strict_types=1);

/**
 * OPR Gateway continuous-sync CLI — pull clinical resources from an incumbent
 * EHR snapshot, classify new/changed against persisted state, auto-verify the
 * deterministic candidates, and commit them (as superseding entries where
 * applicable) into an OPR vault via the public API.
 *
 * The gateway library is dependency-free and ships no HTTP client, so this CLI
 * can read the "source" either as a FHIR Bundle JSON file (SnapshotFileFhirSource)
 * — e.g. a scheduled export from the incumbent EHR — or, at deployment time,
 * poll the incumbent's live FHIR API directly (HttpFhirSource). Exactly one of
 * --bundle / --fhir-base is required; both implement the same FhirSource
 * interface, so nothing else in SyncEngine changes.
 *
 *   php bin/sync.php --bundle=snapshot.json --state=sync-state.json \
 *                     --source-id=incumbent-ehr --types=MedicationStatement,AllergyIntolerance \
 *                     --base-url=http://localhost:8000 --vault=<uuid> --token=<write-grant-token> \
 *                     --verifier-id=u1 --verifier-name="Dr. Okafor" [--acknowledge-unresolved] [--dry-run]
 *
 *   php bin/sync.php --fhir-base=https://ehr.example/fhir --fhir-token=<incumbent-bearer-token> \
 *                     --state=sync-state.json --source-id=incumbent-ehr \
 *                     --types=MedicationStatement,AllergyIntolerance \
 *                     --base-url=http://localhost:8000 --vault=<uuid> --token=<write-grant-token> \
 *                     --verifier-id=u1 --verifier-name="Dr. Okafor" [--acknowledge-unresolved] [--dry-run]
 */

require __DIR__.'/../vendor/autoload.php';

use Opr\Gateway\Candidate;
use Opr\Gateway\IngestionResult;
use Opr\Gateway\Sync\FhirSource;
use Opr\Gateway\Sync\HttpFhirSource;
use Opr\Gateway\Sync\SnapshotFileFhirSource;
use Opr\Gateway\Sync\SyncEngine;
use Opr\Gateway\Sync\SyncItem;
use Opr\Gateway\Sync\JsonFileSyncStateStore;
use Opr\Gateway\VaultClient;
use Opr\Gateway\Verification;

$opt = getopt('', [
    'bundle:', 'fhir-base:', 'fhir-token:', 'state:', 'source-id:', 'types:',
    'base-url:', 'vault:', 'token:', 'verifier-id:', 'verifier-name:',
    'acknowledge-unresolved', 'dry-run',
]);

foreach (['state', 'source-id', 'types', 'verifier-id', 'verifier-name'] as $required) {
    if (empty($opt[$required])) {
        fwrite(STDERR, "missing --{$required}\n");
        exit(2);
    }
}

$hasBundle = ! empty($opt['bundle']);
$hasFhirBase = ! empty($opt['fhir-base']);
if ($hasBundle === $hasFhirBase) {
    fwrite(STDERR, "exactly one of --bundle or --fhir-base is required\n");
    exit(2);
}
if ($hasFhirBase && empty($opt['fhir-token'])) {
    fwrite(STDERR, "missing --fhir-token (required with --fhir-base)\n");
    exit(2);
}

$types = array_map('trim', explode(',', $opt['types']));

// Exclusive run lock: two concurrent syncs against one state file silently
// lose each other's updates (full-file overwrite from a stale snapshot) and
// re-create already-committed entries as duplicates. Fail loudly instead.
$lockPath = $opt['state'].'.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another sync run holds {$lockPath}; refusing to run concurrently.\n");
    exit(3);
}

/** @var FhirSource $source */
$source = $hasFhirBase
    ? new HttpFhirSource($opt['fhir-base'], $opt['fhir-token'])
    : new SnapshotFileFhirSource($opt['bundle']);
$state = new JsonFileSyncStateStore($opt['state']);
$engine = new SyncEngine($source, $state, $opt['source-id']);

$plan = $engine->pull($types);

echo "Sync plan for source '{$opt['source-id']}':\n";
foreach ($plan->counts as $type => $counts) {
    printf(
        "  %-22s fetched %d, new %d, changed %d, unchanged %d, unresolved %d\n",
        $type, $counts['fetched'], $counts['new'], $counts['changed'], $counts['unchanged'], $counts['unresolved'],
    );
}

$candidates = $engine->toCandidates($plan);
echo 'Candidates: '.count($candidates)."\n";

// Route candidates through the same completeness-first verification as a
// one-shot import: build an IngestionResult so Verification's blocked-signoff
// rules apply identically to sync output.
$result = new IngestionResult();
$result->classification = 'fhir-sync';
foreach ($candidates as $candidate) {
    $result->add($candidate);
}
foreach ($plan->itemsOf(SyncItem::UNRESOLVED) as $item) {
    $result->noteUnextractedMention($item->resourceType);
}

$verification = new Verification($result);
$accepted = $verification->acceptAllDeterministic();
echo "Auto-accepted deterministic candidates: {$accepted}\n";

if (! empty($opt['acknowledge-unresolved'])) {
    $verification->acknowledgeUnresolved();
}

try {
    $entries = $verification->signOff($opt['verifier-id'], $opt['verifier-name']);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Sign-off blocked: '.$e->getMessage()."\n");
    fwrite(STDERR, "Re-run with --acknowledge-unresolved once the unresolved mentions are handled.\n");
    exit(1);
}

if (isset($opt['dry-run'])) {
    echo "Dry run — ".count($entries)." entries ready to commit. State not updated.\n";
    echo json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

foreach (['base-url', 'vault', 'token'] as $required) {
    if (empty($opt[$required])) {
        fwrite(STDERR, "missing --{$required} (required to commit; use --dry-run to preview)\n");
        exit(2);
    }
}

$client = new VaultClient($opt['base-url'], $opt['token'], $opt['vault']);

// Commit ONE entry at a time and record its state IMMEDIATELY (review
// findings): (a) commitOne() returns the REAL per-entry vault id, so future
// CHANGED classifications supersede the right entry; (b) interleaving commit
// and record shrinks the crash window to a single entry — a crash between the
// two can duplicate at most one resource on the next run, not the whole batch.
$accepted = array_values(array_filter(
    $candidates,
    static fn (Candidate $c): bool => in_array($c->disposition, ['accepted', 'edited'], true),
));
$committed = 0;
$chainHead = null;
foreach ($entries as $i => $entry) {
    $result = $client->commitOne($entry);
    $committed++;
    $chainHead = $result['chain_hash'] ?? $chainHead;

    $candidate = $accepted[$i] ?? null;
    $sourceKey = $candidate?->provenance['source_key'] ?? null;
    $versionId = $candidate?->provenance['source_version_id'] ?? '';
    $fingerprint = $candidate?->provenance['content_fingerprint'] ?? null;
    if (is_string($sourceKey) && is_string($fingerprint)) {
        $engine->recordCommitted($sourceKey, (string) $versionId, $result['id'], $fingerprint);
    }
}

echo "Committed {$committed} entries.\n";
echo "Vault chain head: {$chainHead}\n";

// Checkpoint = the captured PULL-START time (never post-commit "now" — a
// resource updated mid-run would otherwise be silently unfetchable forever).
$engine->markPulled();
