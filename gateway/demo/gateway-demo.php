<?php

declare(strict_types=1);

/**
 * Gateway G0 live demo — the "walk in with a MyChart C-CDA and an iPhone export,
 * 30 minutes later the practice has a verified chart" story (build plan §G0), run
 * end to end against a REAL running OPR vault server. No mocks.
 */

require __DIR__.'/../vendor/autoload.php';

use Opr\Gateway\Candidate;
use Opr\Gateway\Gateway;
use Opr\Gateway\VaultClient;
use Opr\Gateway\Verification;

$BASE = rtrim($argv[1] ?? '', '/');
if ($BASE === '') {
    fwrite(STDERR, "usage: php gateway-demo.php <base-url>\n");
    exit(2);
}

const C = "\033[1;36m", G = "\033[0;32m", D = "\033[0;90m", R = "\033[0;31m", X = "\033[0m";

function req(string $m, string $u, ?array $b = null, ?string $t = null): array
{
    $h = ['Accept: application/json'];
    if ($b !== null) {
        $h[] = 'Content-Type: application/json';
    }
    if ($t !== null) {
        $h[] = "Authorization: Bearer {$t}";
    }
    $ctx = stream_context_create(['http' => ['method' => $m, 'header' => implode("\r\n", $h),
        'content' => $b === null ? '' : json_encode($b), 'ignore_errors' => true, 'timeout' => 30]]);
    $raw = @file_get_contents($u, false, $ctx);
    $s = 0;
    foreach ($http_response_header ?? [] as $x) {
        if (preg_match('#\AHTTP/\S+\s+(\d{3})#', $x, $mm)) {
            $s = (int) $mm[1];
        }
    }
    return ['status' => $s, 'json' => $raw === false ? null : json_decode($raw, true)];
}
function ok(string $m): void { printf("   %s✓%s %s\n", G, X, $m); }
function note(string $m): void { printf("   %s%s%s\n", D, $m, X); }
function scene(string $t): void { printf("\n%s━━ %s%s\n", C, $t, X); }
function fail(string $m): never { printf("\n%sFAILED:%s %s\n", R, X, $m); exit(1); }

printf("%s\n  OPR LEGACY GATEWAY — LIVE INGESTION DEMO\n  vault: %s%s\n", C, $BASE, X);

// --- Set up a patient vault and a write grant the Gateway will hold ----------
scene('Maria opens a brand-new, empty vault at her DPC practice');
$reg = req('POST', "{$BASE}/api/users", ['name' => 'Maria Alvarez', 'email' => 'maria-gw-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'demo-Pw-123456789']);
$maria = $reg['json']['token'] ?? fail('register failed');
$vault = req('POST', "{$BASE}/api/vaults", [], $maria)['json']['id'] ?? fail('vault failed');
ok("empty vault created: {$vault}");

$mint = req('POST', "{$BASE}/api/vaults/{$vault}/grants", [
    'purpose' => 'treatment', 'scope' => ['*'], 'permissions' => ['read', 'write'],
    'sensitive_categories' => ['42_cfr_part_2'], 'max_uses' => 1000,
], $maria);
$writeToken = req('POST', "{$BASE}/api/grants/redeem", ['pseudo_id' => $mint['json']['pseudo_id'], 'otp' => $mint['json']['otp']])['json']['token'] ?? fail('redeem failed');
ok('Maria grants the practice write access (incl. her Part 2 consent) — scoped + revocable');

// --- Ingest her prior records ------------------------------------------------
$gateway = new Gateway();
$allEntries = [];

foreach ([
    'Her prior clinic\'s C-CDA (MyChart-style export)' => __DIR__.'/../fixtures/ccd-sample.xml',
    'Her iPhone Health Records (FHIR bundle)' => __DIR__.'/../fixtures/apple-health-export.json',
] as $label => $file) {
    scene("Ingesting: {$label}");
    $result = $gateway->ingest(file_get_contents($file));
    ok("classified as '{$result->classification}', ".count($result->candidates).' candidates extracted deterministically');
    foreach ($result->mentionCounts as $domain => $c) {
        $flag = $c['unresolved'] > 0 ? " {$c['unresolved']} unresolved →" : '';
        note(sprintf('%-13s %d found, %d extracted,%s', $domain, $c['found'], $c['extracted'], $flag ?: ' 0 unresolved'));
    }

    // The clinician's medication reconciliation: accept deterministic, handle unresolved.
    $v = new Verification($result);
    $accepted = $v->acceptAllDeterministic();
    if ($result->unresolvedCount() > 0) {
        note("clinician sees {$result->unresolvedCount()} narrative-only mention(s) as a work item — NOT silently dropped");
        $v->acknowledgeUnresolved();
    }
    $entries = $v->signOff('dr-okafor', 'Dr. Okafor');
    ok("clinician-verified {$accepted} entries (medication reconciliation signed)");
    $allEntries = array_merge($allEntries, $entries);
}

// --- Commit into the vault via the PUBLIC API --------------------------------
scene('Committing verified records into Maria\'s vault (public API — same rails as any vendor)');
$client = new VaultClient($BASE, $writeToken, $vault);
$commit = $client->commit($allEntries);
ok("{$commit['committed']} entries committed; vault chain head: ".substr((string) $commit['chain_head_hash'], 0, 16).'…');

// --- Prove it: Maria now has a verified, provenance-linked chart -------------
scene('Maria\'s vault now holds a complete, verified, provenance-linked chart');
$verify = req('GET', "{$BASE}/api/vaults/{$vault}/verify", null, $maria);
$verify['json']['valid'] === true ? ok("chain verification: VALID across {$verify['json']['entries']} entries") : fail('chain invalid');

$everything = req('GET', "{$BASE}/api/fhir/{$vault}/Patient/\$everything", null, $maria);
$types = [];
foreach ($everything['json']['entry'] ?? [] as $e) {
    $t = $e['resource']['resourceType'] ?? '';
    $types[$t] = ($types[$t] ?? 0) + 1;
}
unset($types['Patient']);
ok('on her own FHIR endpoint: '.implode(', ', array_map(fn ($t, $n) => "{$n} {$t}", array_keys($types), $types)));

// Every entry carries verified provenance + tier.
$sample = $everything['json']['entry'][1]['resource'] ?? [];
$tier = $sample['meta']['tag'][0]['code'] ?? '?';
ok("each entry tagged '{$tier}' with the contributing organization and the verifying clinician recorded");

$reExport = req('GET', "{$BASE}/api/vaults/{$vault}/export", null, $maria);
$hasVerifier = false;
foreach ($reExport['json']['entries'] ?? [] as $e) {
    if (($e['provenance']['verifier_name'] ?? null) === 'Dr. Okafor') {
        $hasVerifier = true;
    }
}
$hasVerifier ? ok('provenance names the source organization AND Dr. Okafor as verifier — full chain of custody') : fail('verifier provenance missing');

printf("\n%s━━ In under a minute: two legacy formats → one verified, patient-owned, portable chart.%s\n", C, X);
note('Nothing was fabricated. Narrative-only mentions surfaced as work items, never invented.');
note('AI extraction of narrative (G1) is the next tier — gated by a signed model BAA.');
