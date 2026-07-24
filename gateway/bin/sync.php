<?php

declare(strict_types=1);

/**
 * OPR Gateway continuous-sync CLI — pull clinical resources from an incumbent
 * EHR snapshot, classify new/changed against persisted state, auto-verify the
 * deterministic candidates, and commit them (as superseding entries where
 * applicable) into an OPR vault via the public API.
 *
 * The gateway library is dependency-free and ships no HTTP client, so this CLI
 * reads the "source" as a FHIR Bundle JSON file (SnapshotFileFhirSource) —
 * e.g. a scheduled export from the incumbent EHR. That file IS the FhirSource
 * boundary: at deployment time, swap in a live HTTP FhirSource (polling the
 * incumbent's FHIR API) implementing the exact same interface; nothing else
 * in SyncEngine changes.
 *
 *   php bin/sync.php --bundle=snapshot.json --state=sync-state.json \
 *                     --source-id=incumbent-ehr --types=MedicationStatement,AllergyIntolerance \
 *                     --base-url=http://localhost:8000 --vault=<uuid> --token=<write-grant-token> \
 *                     --verifier-id=u1 --verifier-name="Dr. Okafor" [--acknowledge-unresolved] [--dry-run]
 */

require __DIR__.'/../vendor/autoload.php';

use Opr\Gateway\Candidate;
use Opr\Gateway\IngestionResult;
use Opr\Gateway\Sync\SnapshotFileFhirSource;
use Opr\Gateway\Sync\SyncEngine;
use Opr\Gateway\Sync\SyncItem;
use Opr\Gateway\Sync\JsonFileSyncStateStore;
use Opr\Gateway\VaultClient;
use Opr\Gateway\Verification;

$opt = getopt('', [
    'bundle:', 'state:', 'source-id:', 'types:',
    'base-url:', 'vault:', 'token:', 'verifier-id:', 'verifier-name:',
    'acknowledge-unresolved', 'dry-run',
]);

foreach (['bundle', 'state', 'source-id', 'types', 'verifier-id', 'verifier-name'] as $required) {
    if (empty($opt[$required])) {
        fwrite(STDERR, "missing --{$required}\n");
        exit(2);
    }
}

$types = array_map('trim', explode(',', $opt['types']));

$source = new SnapshotFileFhirSource($opt['bundle']);
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
$commit = $client->commit($entries);

echo "Committed {$commit['committed']} entries.\n";
echo "Vault chain head: {$commit['chain_head_hash']}\n";

// Record commit outcomes into sync state so the next pull sees these as
// unchanged, and mark the pull time. Entry order matches $entries order,
// which matches the accepted/edited candidates order from signOff — but
// signOff does not expose sourceKey, so we recompute the accepted set here
// from $candidates directly for the state update (accepted+edited only).
$committedIndex = 0;
foreach ($candidates as $candidate) {
    if (! in_array($candidate->disposition, ['accepted', 'edited'], true)) {
        continue;
    }
    $sourceKey = $candidate->provenance['source_key'] ?? null;
    $versionId = $candidate->provenance['source_version_id'] ?? '';
    $fingerprint = $candidate->provenance['content_fingerprint'] ?? null;
    if (is_string($sourceKey) && is_string($fingerprint) && isset($entries[$committedIndex])) {
        // The gateway holds only a write grant, so the vault entry id for
        // THIS commit isn't returned per-entry (only the chain head hash) —
        // record the head hash as the best available id. Deployments needing
        // exact per-entry ids should extend VaultClient::commit's response.
        $engine->recordCommitted($sourceKey, (string) $versionId, (string) ($commit['chain_head_hash'] ?? ''), $fingerprint);
    }
    $committedIndex++;
}
$engine->markPulled(gmdate('c'));
