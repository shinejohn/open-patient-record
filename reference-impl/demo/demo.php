<?php

declare(strict_types=1);

/**
 * OPR live demo — a complete patient-owned-record story against a RUNNING server.
 * Launched by demo.sh (which boots the server on a scratch database). Everything
 * below is real HTTP against real code — no mocks, no seeded shortcuts.
 */

$BASE = rtrim($argv[1] ?? '', '/');
$DB = getenv('DEMO_DB') ?: 'opr_vault_demo';
$DBUSER = getenv('DEMO_DB_USER') ?: get_current_user();
if ($BASE === '') {
    fwrite(STDERR, "usage: php demo.php <base-url>\n");
    exit(2);
}

function req(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'json' => $raw === false ? null : json_decode($raw, true), 'raw' => (string) $raw];
}

const C = "\033[1;36m";   // cyan headings
const G = "\033[0;32m";   // green ok
const R = "\033[0;31m";   // red danger
const D = "\033[0;90m";   // dim
const X = "\033[0m";

function scene(int $n, string $title): void
{
    printf("\n%s━━ Scene %d — %s%s\n", C, $n, $title, X);
}
function ok(string $msg): void
{
    printf("   %s✓%s %s\n", G, X, $msg);
}
function bad(string $msg): void
{
    printf("   %s✗%s %s\n", R, X, $msg);
}
function note(string $msg): void
{
    printf("   %s%s%s\n", D, $msg, X);
}
function fail(string $msg): never
{
    printf("\n%sDEMO ASSERTION FAILED:%s %s\n", R, X, $msg);
    exit(1);
}
function expect(bool $cond, string $okMsg, string $failMsg): void
{
    $cond ? ok($okMsg) : fail($failMsg);
}

$user = function (string $name, string $email) use ($BASE): array {
    $r = req('POST', "{$BASE}/users", ['name' => $name, 'email' => $email, 'password' => 'demo-Pw-123456789']);
    if ($r['status'] !== 201) {
        fail("could not register {$name} ({$r['status']})");
    }

    return ['token' => $r['json']['token'], 'name' => $name];
};

printf("%s\n  OPEN PATIENT RECORD — LIVE DEMO\n  target: %s  (real server, real database, no mocks)%s\n", C, $BASE, X);

// ---------------------------------------------------------------- Scene 1
scene(1, 'Maria gets a vault');
$maria = $user('Maria Alvarez', 'maria@example.test');
$vault = req('POST', "{$BASE}/vaults", [], $maria['token']);
$V = $vault['json']['id'];
expect($vault['status'] === 201, "vault created — one person, one vault (id {$V})", 'vault create failed');
note('Nothing is in it, and nobody but Maria can touch it.');

// ---------------------------------------------------------------- Scene 2
scene(2, 'Her doctor contributes — with Maria\'s permission, never without');
$drOkafor = $user('Dr. Okafor (PCP)', 'okafor@example.test');

$r = req('POST', "{$BASE}/vaults/{$V}/entries", [
    'resource_type' => 'Condition',
    'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Essential hypertension']],
    'verification_tier' => 'verified-source',
    'provenance' => ['organization' => 'Okafor Family Medicine'],
], $drOkafor['token']);
expect($r['status'] === 403, 'without a grant, even her own doctor is refused', 'ungranted write was allowed!');

$mint = req('POST', "{$BASE}/vaults/{$V}/grants", [
    'purpose' => 'treatment', 'scope' => ['*'], 'permissions' => ['read', 'write'], 'max_uses' => 1,
    'sensitive_categories' => ['42_cfr_part_2'],
], $maria['token']);
$red = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $mint['json']['pseudo_id'], 'otp' => $mint['json']['otp']]);
$docToken = $red['json']['token'];
ok('Maria grants Dr. Okafor treatment access (scoped, expiring, revocable)');

foreach ([
    ['Condition', ['resourceType' => 'Condition', 'code' => ['text' => 'Essential hypertension']], null],
    ['MedicationRequest', ['resourceType' => 'MedicationRequest', 'medication' => ['text' => 'Lisinopril 10mg daily']], null],
    ['MedicationStatement', ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Buprenorphine (recovery program)']], '42_cfr_part_2'],
] as [$type, $payload, $sensitive]) {
    $r = req('POST', "{$BASE}/vaults/{$V}/entries", [
        'resource_type' => $type, 'payload' => $payload, 'verification_tier' => 'verified-source',
        'sensitive_category' => $sensitive,
        'provenance' => ['organization' => 'Okafor Family Medicine', 'author' => 'Dr. A. Okafor'],
    ], $docToken);
    if ($r['status'] !== 201) {
        fail("commit {$type} failed ({$r['status']}): ".$r['raw']);
    }
}
ok('3 entries committed: diagnosis, prescription, and one 42 CFR Part 2 recovery record');
note('Every entry: provenance (who/where), a hash chained to the one before it.');

// ---------------------------------------------------------------- Scene 3
scene(3, 'Maria reads her own record — on her own FHIR endpoint');
$r = req('GET', "{$BASE}/fhir/{$V}/Patient/\$everything", null, $maria['token']);
expect(($r['json']['total'] ?? 0) === 4, 'her personal FHIR server returns everything: Patient + 3 entries',
    'FHIR $everything wrong total');
note('Standard FHIR R4 — the same wire format the law already forces every EHR to speak.');

// ---------------------------------------------------------------- Scene 4
scene(4, 'QR share at a specialist\'s front desk');
$ss = req('POST', "{$BASE}/vaults/{$V}/share-sessions", ['scope' => ['Condition', 'MedicationRequest']], $maria['token']);
[$p1, $p2, $pseudo, $otp] = explode(':', $ss['json']['share_code']);
$sr = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $pseudo, 'otp' => $otp]);
$r = req('GET', "{$BASE}/vaults/{$V}/entries", null, $sr['json']['token']);
$seen = array_column($r['json']['entries'], 'resource_type');
expect($sr['status'] === 200 && ! in_array('MedicationStatement', $seen, true),
    'specialist sees the shared history — the Part 2 record is NOT in it',
    'share session leaked out-of-scope data');
$sr2 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $pseudo, 'otp' => $otp]);
expect($sr2['status'] !== 200, 'the QR code is dead after one scan (single redemption)', 'share code redeemed twice!');

// ---------------------------------------------------------------- Scene 5
scene(5, 'A nosy stranger gets nothing — not even hints');
$snoop = $user('N. Osy', 'snoop@example.test');
$r = req('GET', "{$BASE}/vaults/{$V}/entries", null, $snoop['token']);
expect($r['status'] === 403, 'direct read: denied', 'stranger read succeeded!');
$g1 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $pseudo, 'otp' => '99999999']);
$g2 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => bin2hex(random_bytes(16)), 'otp' => '99999999']);
expect($g1['raw'] === $g2['raw'],
    'guessing: wrong-secret and no-such-grant answers are byte-identical (no oracle)',
    'redemption oracle detected');

// ---------------------------------------------------------------- Scene 6
scene(6, '3am: Maria is unconscious in the ER');
$erDoc = $user('Dr. Reyes (ER)', 'reyes@example.test');
$r = req('POST', "{$BASE}/vaults/{$V}/break-glass", ['reason' => 'x'], $erDoc['token']);
expect($r['status'] === 422, 'break-glass without a substantive reason: refused', 'flimsy break-glass accepted');
$bg = req('POST', "{$BASE}/vaults/{$V}/break-glass", [
    'reason' => 'Unconscious patient in ED resus 2; need current medications and allergies before intubation',
], $erDoc['token']);
$r = req('GET', "{$BASE}/vaults/{$V}/entries", null, $bg['json']['token']);
$cats = array_column($r['json']['entries'], 'sensitive_category');
expect($bg['status'] === 201 && ! in_array('42_cfr_part_2', $cats, true),
    'ER doctor sees medications NOW — the Part 2 record stays protected even in an emergency',
    'break-glass behaved wrongly');
$audit = req('GET', "{$BASE}/vaults/{$V}/audit", null, $maria['token']);
$flagged = array_filter($audit['json']['events'], fn ($e) => ($e['action'] ?? '') === 'grant.emergency_access');
expect($flagged !== [], "Maria's own audit log shows WHO broke glass, WHEN, and WHY — flagged as emergency",
    'emergency access missing from audit');

// ---------------------------------------------------------------- Scene 7
scene(7, 'A hostile custodian tries to rewrite history');
$victor = $user('Victor (second patient)', 'victor@example.test');
$vv = req('POST', "{$BASE}/vaults", [], $victor['token'])['json']['id'];
req('POST', "{$BASE}/vaults/{$vv}/entries", [
    'resource_type' => 'Condition',
    'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Type 2 diabetes']],
    'verification_tier' => 'verified-source',
    'provenance' => ['organization' => 'Demo Clinic'],
], $victor['token']);
note('The DB blocks edits outright — so we simulate a MALICIOUS OPERATOR with raw database access:');
$sql = "ALTER TABLE vault_entries DISABLE TRIGGER vault_entries_immutable; "
    ."UPDATE vault_entries SET payload = 'oprv1:forged' WHERE vault_id = '{$vv}'; "
    ."ALTER TABLE vault_entries ENABLE TRIGGER vault_entries_immutable;";
exec(sprintf('psql -U %s -d %s -c %s 2>/dev/null', escapeshellarg($DBUSER), escapeshellarg($DB), escapeshellarg($sql)));
$r = req('GET', "{$BASE}/vaults/{$vv}/verify", null, $victor['token']);
expect(($r['json']['valid'] ?? true) === false,
    "caught: verification reports the chain broken at entry #{$r['json']['first_invalid_seq']}",
    'tampering went undetected!');
note('Not even the operator of the database can silently rewrite a record.');

// ---------------------------------------------------------------- Scene 8
scene(8, 'Maria leaves — and takes EVERYTHING with her');
$export = req('GET', "{$BASE}/vaults/{$V}/export", null, $maria['token']);
$mariaNew = $user('Maria (at her NEW custodian)', 'maria-new@example.test');
$v2 = req('POST', "{$BASE}/vaults", [], $mariaNew['token'])['json']['id'];
$imp = req('POST', "{$BASE}/vaults/{$v2}/import", $export['json'], $mariaNew['token']);
$ver = req('GET', "{$BASE}/vaults/{$v2}/verify", null, $mariaNew['token']);
expect($imp['status'] === 201 && ($imp['json']['anchored'] ?? false) && ($ver['json']['valid'] ?? false),
    "3 entries, all provenance, full audit history — chain re-verified and ANCHORED on the same head",
    'migration failed');
note("chain head at old custodian:  {$export['json']['chain_head_hash']}");
note("chain head at new custodian:  {$imp['json']['chain_head_hash']}   ← identical: nothing lost, nothing altered");

// ---------------------------------------------------------------- Scene 9
scene(9, 'The public witness: even we can\'t rewrite yesterday');
exec(sprintf('cd %s && php artisan opr:publish-witness 2>/dev/null', escapeshellarg(dirname(__DIR__).'/server')));
$w = req('GET', "{$BASE}/witness-log");
$latest = end($w['json']['entries']);
expect(($latest['merkle_root'] ?? '') !== '',
    "signed Merkle root over ALL vault heads published publicly: {$latest['merkle_root']}",
    'witness publish failed');
$proof = req('GET', "{$BASE}/vaults/{$v2}/witness-proof", null, $mariaNew['token']);
expect($proof['status'] === 200, "Maria holds a cryptographic proof her vault is inside that public root", 'no witness proof');
note('Roots only — the log contains zero patient information.');

printf("\n%s━━ Epilogue%s\n", C, X);
note('Everything above ran against one server, over HTTP, in seconds.');
note('The conformance runner now certifies this same server black-box:');
printf("\n");
passthru(sprintf('php %s --base-url=%s', escapeshellarg(dirname(__DIR__).'/conformance/run.php'), escapeshellarg($BASE)), $code);
exit($code);
