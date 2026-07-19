<?php

declare(strict_types=1);

/**
 * OPR Conformance Runner v0.1 — black-box, dependency-free.
 *
 * Runs the custody-layer conformance checks against ANY implementation over plain
 * HTTP. No framework, no composer install — just PHP 8.2+.
 *
 * Usage:
 *   php run.php --base-url=http://localhost:8000/api
 *
 * Account provisioning is implementation-specific. By default the runner
 * self-provisions via POST {base}/users (the reference server's shape). To test a
 * server with different provisioning, pre-create two subject accounts and pass
 * their tokens:
 *   php run.php --base-url=... --token-a=... --token-b=... --token-c=...
 *   (A: subject under test; B: migration destination subject; C: a stranger)
 *
 * Exit code 0 = all REQUIRED checks pass. SHOULD-level checks report but don't fail.
 */

error_reporting(E_ALL);

$options = getopt('', ['base-url:', 'token-a::', 'token-b::', 'token-c::']);
$BASE = rtrim($options['base-url'] ?? '', '/');
if ($BASE === '') {
    fwrite(STDERR, "Missing --base-url\n");
    exit(2);
}

// ---------------------------------------------------------------- HTTP helper

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
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'json' => $raw === false ? null : json_decode($raw, true), 'raw' => $raw];
}

// ------------------------------------------------------------- check harness

$results = ['required' => [0, 0], 'should' => [0, 0]];

function check(string $level, string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[$level][$pass ? 0 : 1]++;
    printf(
        "  [%s] %-4s %s%s\n",
        $pass ? 'PASS' : 'FAIL',
        strtoupper($level === 'required' ? 'MUST' : 'SHLD'),
        $name,
        ($pass || $detail === '') ? '' : "  — {$detail}",
    );
}

$must = fn (string $name, bool $pass, string $detail = '') => check('required', $name, $pass, $detail);
$should = fn (string $name, bool $pass, string $detail = '') => check('should', $name, $pass, $detail);

// ------------------------------------------------------------- provisioning

echo "OPR Conformance Runner v0.1 — target: {$BASE}\n\nProvisioning:\n";

function provision(string $base, string $label): array
{
    $email = sprintf('conf-%s-%s@example.test', $label, bin2hex(random_bytes(6)));
    $r = req('POST', "{$base}/users", ['name' => "Conformance {$label}", 'email' => $email, 'password' => 'conformance-Pw-12345']);
    if ($r['status'] !== 201) {
        fwrite(STDERR, "Self-provisioning failed ({$r['status']}). Pass --token-a/b/c for pre-provisioned accounts.\n");
        exit(2);
    }

    return ['token' => $r['json']['token']];
}

$A = isset($options['token-a']) ? ['token' => $options['token-a']] : provision($BASE, 'a');
$B = isset($options['token-b']) ? ['token' => $options['token-b']] : provision($BASE, 'b');
$C = isset($options['token-c']) ? ['token' => $options['token-c']] : provision($BASE, 'c');
echo "  three subjects ready\n\nChecks:\n";

// --------------------------------------------------------------- the checks

// FHIR front door
$r = req('GET', "{$BASE}/fhir/metadata");
$must('FHIR CapabilityStatement at /fhir/metadata (R4, no auth)',
    $r['status'] === 200 && ($r['json']['resourceType'] ?? '') === 'CapabilityStatement'
    && str_starts_with($r['json']['fhirVersion'] ?? '', '4.'));

// Vault + commit
$vaultA = req('POST', "{$BASE}/vaults", [], $A['token'])['json']['id'] ?? null;
$must('subject can create a vault', $vaultA !== null);

$entry = fn (array $over = []) => array_replace([
    'resource_type' => 'Condition',
    'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
    'verification_tier' => 'verified-source',
    'provenance' => ['organization' => 'Conformance Clinic'],
], $over);

$e1 = req('POST', "{$BASE}/vaults/{$vaultA}/entries", $entry(), $A['token']);
$must('commit with provenance succeeds', $e1['status'] === 201 && strlen($e1['json']['chain_hash'] ?? '') === 64);

$r = req('POST', "{$BASE}/vaults/{$vaultA}/entries", array_diff_key($entry(), ['provenance' => 1]), $A['token']);
$must('commit WITHOUT provenance is rejected (spec §5)', $r['status'] >= 400);

$e2 = req('POST', "{$BASE}/vaults/{$vaultA}/entries", $entry([
    'resource_type' => 'MedicationStatement',
    'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Buprenorphine']],
    'sensitive_category' => '42_cfr_part_2',
]), $A['token']);

// Append-only (spec §4.1)
$r = req('DELETE', "{$BASE}/vaults/{$vaultA}/entries/{$e1['json']['id']}", null, $A['token']);
$must('DELETE of a committed entry returns 405 + OperationOutcome (spec §4.1)',
    $r['status'] === 405 && ($r['json']['resourceType'] ?? '') === 'OperationOutcome');

// Chain verification (spec §4.2)
$r = req('GET', "{$BASE}/vaults/{$vaultA}/verify", null, $A['token']);
$must('chain verification reports valid', $r['status'] === 200 && ($r['json']['valid'] ?? false) === true);

// Grants (spec §3)
$mintBody = ['purpose' => 'treatment', 'scope' => ['Condition'], 'permissions' => ['read'], 'max_uses' => 2];
$r = req('POST', "{$BASE}/vaults/{$vaultA}/grants", $mintBody, $C['token']);
$must('only the subject can mint a grant (spec §3.1)', $r['status'] === 403);

$mint = req('POST', "{$BASE}/vaults/{$vaultA}/grants", $mintBody, $A['token']);
$must('subject mints a grant; secret returned once', $mint['status'] === 201 && isset($mint['json']['otp']));

$red = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $mint['json']['pseudo_id'], 'otp' => $mint['json']['otp']]);
$must('redemption yields a working token (spec §3.3)', $red['status'] === 200 && isset($red['json']['token']));

$r = req('GET', "{$BASE}/vaults/{$vaultA}/entries", null, $red['json']['token']);
$types = array_column($r['json']['entries'] ?? [], 'resource_type');
$cats = array_column($r['json']['entries'] ?? [], 'sensitive_category');
$must('grant scope filtering applies', $r['status'] === 200 && ! in_array('MedicationStatement', $types, true));
$must('sensitive categories excluded by default (spec §3.2)', ! in_array('42_cfr_part_2', $cats, true));

$bad1 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $mint['json']['pseudo_id'], 'otp' => '00000000']);
$bad2 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => bin2hex(random_bytes(16)), 'otp' => '00000000']);
$must('no redemption oracle: wrong secret ≡ unknown handle (spec §3.3)',
    $bad1['status'] === $bad2['status'] && $bad1['raw'] === $bad2['raw']);

$rev = req('POST', "{$BASE}/vaults/{$vaultA}/grants/{$mint['json']['grant_id']}/revoke", [], $A['token']);
$r = req('GET', "{$BASE}/vaults/{$vaultA}/entries", null, $red['json']['token']);
$must('revocation invalidates outstanding tokens (spec §3.4)', $rev['status'] === 200 && in_array($r['status'], [401, 403], true));

// Portability (spec §7)
$export = req('GET', "{$BASE}/vaults/{$vaultA}/export", null, $A['token']);
$must('complete export with chain head (spec §7.1)',
    $export['status'] === 200 && isset($export['json']['chain_head_hash'])
    && count($export['json']['entries'] ?? []) === 2 && isset($export['json']['audit_events']));

$vaultB = req('POST', "{$BASE}/vaults", [], $B['token'])['json']['id'] ?? null;
$imp = req('POST', "{$BASE}/vaults/{$vaultB}/import", $export['json'], $B['token']);
$verB = req('GET', "{$BASE}/vaults/{$vaultB}/verify", null, $B['token']);
$headB = req('GET', "{$BASE}/vaults/{$vaultB}", null, $B['token'])['json']['chain_head_hash'] ?? null;
$must('round-trip import succeeds and chain anchors on the source head (spec §7.2/§7.3)',
    $imp['status'] === 201 && ($verB['json']['valid'] ?? false) === true
    && $headB === $export['json']['chain_head_hash']);

$tampered = $export['json'];
$tampered['entries'][0]['payload']['code']['text'] = 'Tampered';
$vaultB2 = null; // reuse B is non-empty now; a tampered import must fail regardless
$r = req('POST', "{$BASE}/vaults/{$vaultB}/import", $tampered, $B['token']);
$must('tampered export is rejected on import', $r['status'] >= 400);

// SHOULD-level features
$ss = req('POST', "{$BASE}/vaults/{$vaultA}/share-sessions", [], $A['token']);
if ($ss['status'] === 404) {
    $should('ShareSession endpoint (spec §3.6)', false, 'not implemented');
} else {
    $parts = explode(':', $ss['json']['share_code'] ?? '');
    $ok = $ss['status'] === 201 && count($parts) === 4;
    if ($ok) {
        $sr = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $parts[2], 'otp' => $parts[3]]);
        $sr2 = req('POST', "{$BASE}/grants/redeem", ['pseudo_id' => $parts[2], 'otp' => $parts[3]]);
        $ok = $sr['status'] === 200 && $sr2['status'] !== 200;
    }
    $should('ShareSession: QR code redeems exactly once (spec §3.6)', $ok);
}

$bg = req('POST', "{$BASE}/vaults/{$vaultA}/break-glass", ['reason' => 'Conformance: unconscious patient scenario'], $C['token']);
if ($bg['status'] === 404) {
    $should('break-glass endpoint (spec §3.7)', false, 'not implemented');
} else {
    $ok = $bg['status'] === 201;
    if ($ok) {
        $r = req('GET', "{$BASE}/vaults/{$vaultA}/entries", null, $bg['json']['token']);
        $cats = array_column($r['json']['entries'] ?? [], 'sensitive_category');
        $audit = req('GET', "{$BASE}/vaults/{$vaultA}/audit", null, $A['token']);
        $flagged = false;
        foreach ($audit['json']['events'] ?? [] as $ev) {
            if (($ev['action'] ?? '') === 'grant.emergency_access' && ($ev['is_emergency'] ?? false)) {
                $flagged = true;
            }
        }
        $ok = $r['status'] === 200 && ! in_array('42_cfr_part_2', $cats, true) && $flagged;
    }
    $should('break-glass: reads, excludes sensitive, audited as emergency (spec §3.7)', $ok);
}

$w = req('GET', "{$BASE}/witness-log");
$should('public witness log (spec §4.5)', $w['status'] === 200 && isset($w['json']['entries']));

// ------------------------------------------------------------------ summary

[$mp, $mf] = $results['required'];
[$sp, $sf] = $results['should'];
printf("\nREQUIRED: %d passed, %d failed.  SHOULD: %d passed, %d failed.\n", $mp, $mf, $sp, $sf);
echo $mf === 0
    ? "Result: CONFORMANT (Custodian, v0.1 draft checks)\n"
    : "Result: NOT CONFORMANT\n";
exit($mf === 0 ? 0 : 1);
