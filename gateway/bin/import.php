<?php

declare(strict_types=1);

/**
 * OPR Gateway CLI — ingest a legacy record file, auto-verify the deterministic
 * candidates, and commit them into an OPR vault via the public API.
 *
 *   php bin/import.php --file=record.xml --base-url=http://localhost:8000 \
 *                      --vault=<uuid> --token=<write-grant-token> \
 *                      --verifier-id=u1 --verifier-name="Dr. Okafor" [--acknowledge-unresolved]
 *
 * Deterministic candidates are batch-accepted (the CLI is the automated path);
 * for narrative/unresolved mentions it refuses to sign off unless
 * --acknowledge-unresolved is passed — silence is never success.
 */

require __DIR__.'/../vendor/autoload.php';

use Opr\Gateway\Gateway;
use Opr\Gateway\VaultClient;
use Opr\Gateway\Verification;

$opt = getopt('', ['file:', 'base-url:', 'vault:', 'token:', 'verifier-id:', 'verifier-name:', 'acknowledge-unresolved', 'dry-run']);

foreach (['file', 'verifier-id', 'verifier-name'] as $required) {
    if (empty($opt[$required])) {
        fwrite(STDERR, "missing --{$required}\n");
        exit(2);
    }
}

$result = (new Gateway())->ingestFile($opt['file']);

echo "Classification: {$result->classification}\n";
echo "Candidates:     ".count($result->candidates)."\n";
foreach ($result->mentionCounts as $domain => $counts) {
    printf("  %-13s found %d, extracted %d, unresolved %d\n", $domain, $counts['found'], $counts['extracted'], $counts['unresolved']);
}
echo "Unresolved:     {$result->unresolvedCount()}\n";

if ($result->classification === 'needs-manual-entry') {
    fwrite(STDERR, "This document needs manual entry (no deterministic parser). Nothing committed.\n");
    exit(1);
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
    echo "Dry run — ".count($entries)." entries ready to commit.\n";
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
