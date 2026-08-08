<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F4 remainder: patient statements. Money is integer cents, never floats —
 * the same law as the billing slice this reads from.
 */
final class PatientStatementTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string, patient_id: string, invoice_id: string} */
    private function ctx(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 't', 'user' => ['id' => 'u1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'p1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'g1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1' => Http::response(['resourceType' => 'Bundle', 'type' => 'transaction-response', 'entry' => []], 200),
        ]);

        $p = $this->postJson('/api/register-practice', [
            'practice_name' => 'Riverbend', 'name' => 'Dr. O',
            'email' => 'o@r.test', 'password' => 'correct-horse-battery',
        ])->assertCreated();
        $token = (string) $p->json('token');

        $pid = $this->withToken($token)->postJson('/api/patients', ['name' => 'Ana'])
            ->assertCreated()->json('id');

        $this->withToken($token)->postJson('/api/fee-schedule', [
            'cpt_code' => '99213', 'description' => 'Office visit', 'price_cents' => 12500,
        ])->assertCreated();
        $encId = $this->withToken($token)->postJson("/api/patients/{$pid}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/encounters/{$encId}/sign")->assertOk();
        $invId = $this->withToken($token)->postJson('/api/invoices', [
            'encounter_id' => $encId, 'lines' => [['cpt_code' => '99213']],
        ])->assertCreated()->json('id');
        $this->withToken($token)->postJson("/api/invoices/{$invId}/payments", [
            'amount_cents' => 5000, 'method' => 'cash',
        ])->assertCreated();

        return ['token' => $token, 'patient_id' => $pid, 'invoice_id' => $invId];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_statement_totals_invoices_payments_and_balance_in_cents(): void
    {
        $c = $this->ctx();

        $res = $this->withToken($c['token'])
            ->getJson("/api/patients/{$c['patient_id']}/statement?from=2026-08-01&to=2026-08-31")
            ->assertOk();

        $this->assertCount(1, $res->json('invoices'));
        $this->assertSame(12500, $res->json('invoices.0.total_cents'));
        $this->assertCount(1, $res->json('payments'));
        $this->assertSame(5000, $res->json('payments.0.amount_cents'));
        $this->assertSame(7500, $res->json('balance_cents'));
        $this->assertIsInt($res->json('balance_cents'));
    }

    public function test_statement_excludes_out_of_range_invoices(): void
    {
        $c = $this->ctx();

        $res = $this->withToken($c['token'])
            ->getJson("/api/patients/{$c['patient_id']}/statement?from=2026-01-01&to=2026-01-31")
            ->assertOk();

        $this->assertCount(0, $res->json('invoices'));
        $this->assertSame(0, $res->json('balance_cents'));
    }

    public function test_statement_csv_download(): void
    {
        $c = $this->ctx();

        $res = $this->withToken($c['token'])
            ->get("/api/patients/{$c['patient_id']}/statement?from=2026-08-01&to=2026-08-31&format=csv");

        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('12500', $res->streamedContent());
    }
}
