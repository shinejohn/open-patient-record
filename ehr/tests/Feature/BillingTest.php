<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4 / E3: cash-pay billing + the honest transmission ledger.
 *
 * Money is INTEGER CENTS, never floats (the 4healthcare C30 lesson). A
 * superbill/invoice is generated from a signed encounter's CPT + ICD-10 lines
 * against the practice's fee schedule. Payments record against invoices.
 *
 * The transmission ledger is the honest-outbox law applied here: printing or
 * faxing an Rx/referral records status 'queued' -> 'printed'/'fax_queued' —
 * the status NEVER claims electronic transmission that did not happen. There
 * is no 'sent' state at all in this slice, because nothing can send.
 */
final class BillingTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string, patient_id: string} */
    private function ctx(): array
    {
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
        $pid = $this->withToken($p->json('token'))->postJson('/api/patients', ['name' => 'Ana'])
            ->assertCreated()->json('id');

        return ['token' => $p->json('token'), 'patient_id' => $pid];
    }

    public function test_fee_schedule_stores_prices_as_integer_cents(): void
    {
        $c = $this->ctx();

        $this->withToken($c['token'])->postJson('/api/fee-schedule', [
            'cpt_code' => '99213', 'description' => 'Office visit, established, low', 'price_cents' => 12500,
        ])->assertCreated();

        $this->assertSame(12500, DB::table('fee_schedule_items')->value('price_cents'));

        // Floats are refused outright — money is integer cents, full stop.
        $this->withToken($c['token'])->postJson('/api/fee-schedule', [
            'cpt_code' => '99214', 'description' => 'x', 'price_cents' => 145.50,
        ])->assertStatus(422);
    }

    public function test_invoice_from_a_signed_encounter_prices_lines_from_the_fee_schedule(): void
    {
        $c = $this->ctx();
        $this->withToken($c['token'])->postJson('/api/fee-schedule', [
            'cpt_code' => '99213', 'description' => 'Office visit', 'price_cents' => 12500,
        ])->assertCreated();
        $this->withToken($c['token'])->postJson('/api/fee-schedule', [
            'cpt_code' => '81002', 'description' => 'Urinalysis', 'price_cents' => 900,
        ])->assertCreated();

        $encId = $this->withToken($c['token'])
            ->postJson("/api/patients/{$c['patient_id']}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');
        $this->withToken($c['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();

        $invoice = $this->withToken($c['token'])->postJson('/api/invoices', [
            'encounter_id' => $encId,
            'lines' => [
                ['cpt_code' => '99213', 'icd10_code' => 'R51.9'],
                ['cpt_code' => '81002', 'icd10_code' => 'R30.0'],
            ],
        ])->assertCreated();

        $this->assertSame(13400, $invoice->json('total_cents'));
        $this->assertSame(12500, $invoice->json('lines.0.price_cents'));
    }

    public function test_invoices_require_a_signed_encounter(): void
    {
        $c = $this->ctx();
        $encId = $this->withToken($c['token'])
            ->postJson("/api/patients/{$c['patient_id']}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');

        $this->withToken($c['token'])->postJson('/api/invoices', [
            'encounter_id' => $encId,
            'lines' => [['cpt_code' => '99213']],
        ])->assertStatus(422)->assertJsonPath('error', 'encounter_not_signed');
    }

    public function test_unknown_cpt_code_is_refused_never_priced_at_zero(): void
    {
        $c = $this->ctx();
        $encId = $this->withToken($c['token'])
            ->postJson("/api/patients/{$c['patient_id']}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');
        $this->withToken($c['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();

        $this->withToken($c['token'])->postJson('/api/invoices', [
            'encounter_id' => $encId,
            'lines' => [['cpt_code' => '00000']],
        ])->assertStatus(422)->assertJsonPath('error', 'unknown_cpt_code');

        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_payments_settle_an_invoice_and_partial_payment_stays_open(): void
    {
        $c = $this->ctx();
        $this->withToken($c['token'])->postJson('/api/fee-schedule', [
            'cpt_code' => '99213', 'description' => 'Visit', 'price_cents' => 10000,
        ])->assertCreated();
        $encId = $this->withToken($c['token'])
            ->postJson("/api/patients/{$c['patient_id']}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');
        $this->withToken($c['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();
        $invId = $this->withToken($c['token'])->postJson('/api/invoices', [
            'encounter_id' => $encId, 'lines' => [['cpt_code' => '99213']],
        ])->assertCreated()->json('id');

        $partial = $this->withToken($c['token'])->postJson("/api/invoices/{$invId}/payments", [
            'amount_cents' => 4000, 'method' => 'card',
        ])->assertCreated();
        $this->assertSame('open', $partial->json('invoice_status'));

        $rest = $this->withToken($c['token'])->postJson("/api/invoices/{$invId}/payments", [
            'amount_cents' => 6000, 'method' => 'cash',
        ])->assertCreated();
        $this->assertSame('paid', $rest->json('invoice_status'));

        // Overpayment is refused, not absorbed.
        $this->withToken($c['token'])->postJson("/api/invoices/{$invId}/payments", [
            'amount_cents' => 100, 'method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_transmission_ledger_never_fabricates_a_send(): void
    {
        $c = $this->ctx();

        $tx = $this->withToken($c['token'])->postJson('/api/transmissions', [
            'patient_id' => $c['patient_id'],
            'channel' => 'print',
            'kind' => 'prescription',
            'summary' => 'Amoxicillin 500mg TID x10d',
        ])->assertCreated();

        $this->assertSame('queued', $tx->json('status'));

        $done = $this->withToken($c['token'])->postJson("/api/transmissions/{$tx->json('id')}/complete")
            ->assertOk();
        // print -> 'printed'; fax -> 'fax_queued'. There is NO 'sent' state.
        $this->assertSame('printed', $done->json('status'));

        $this->withToken($c['token'])->postJson('/api/transmissions', [
            'patient_id' => $c['patient_id'], 'channel' => 'electronic',
            'kind' => 'prescription', 'summary' => 'x',
        ])->assertStatus(422); // no electronic rail exists; refusing beats faking
    }
}
