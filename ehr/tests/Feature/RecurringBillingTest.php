<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4 remainder: DPC recurring billing. No card processing — the generator
 * only creates invoices in the existing cash-pay ledger, exactly like a
 * manual superbill. Idempotency is a DATABASE guarantee (unique index on
 * subscription_id + period_key), not just an application-level check.
 */
final class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{token: string, patient_id: string, staff_token: string} */
    private function ctx(): array
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 't', 'user' => ['id' => 'u1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'p1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'g1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
        ]);

        $p = $this->postJson('/api/register-practice', [
            'practice_name' => 'Riverbend', 'name' => 'Dr. O',
            'email' => 'o@r.test', 'password' => 'correct-horse-battery',
        ])->assertCreated();
        $token = (string) $p->json('token');
        $pid = $this->withToken($token)->postJson('/api/patients', ['name' => 'Ana'])
            ->assertCreated()->json('id');

        $staff = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Sam', 'email' => 'sam@r.test',
            'password' => 'correct-horse-battery', 'role' => 'staff',
        ])->assertCreated();

        return ['token' => $token, 'patient_id' => $pid, 'staff_token' => (string) $staff->json('token')];
    }

    public function test_owner_can_crud_subscriptions_staff_cannot_create(): void
    {
        $c = $this->ctx();

        $id = $this->withToken($c['token'])->postJson('/api/subscriptions', [
            'patient_id' => $c['patient_id'], 'plan_name' => 'DPC Basic',
            'amount_cents' => 7500, 'anchor_day' => 1,
        ])->assertCreated()->json('id');

        $this->withToken($c['staff_token'])->postJson('/api/subscriptions', [
            'patient_id' => $c['patient_id'], 'plan_name' => 'Blocked', 'amount_cents' => 100,
        ])->assertStatus(403);

        $this->withToken($c['token'])->patchJson("/api/subscriptions/{$id}", ['active' => false])
            ->assertOk()->assertJsonPath('active', false);

        $this->withToken($c['token'])->deleteJson("/api/subscriptions/{$id}")->assertOk();
        $this->assertSame(0, DB::table('subscriptions')->count());
    }

    public function test_recurring_command_generates_one_invoice_per_active_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00'));
        $c = $this->ctx();

        $this->withToken($c['token'])->postJson('/api/subscriptions', [
            'patient_id' => $c['patient_id'], 'plan_name' => 'DPC Basic',
            'amount_cents' => 7500, 'anchor_day' => 1,
        ])->assertCreated();

        Artisan::call('billing:run-recurring');

        $this->assertSame(1, DB::table('invoices')->count());
        $invoice = DB::table('invoices')->first();
        $this->assertSame(7500, (int) $invoice->total_cents);
        $this->assertNull($invoice->encounter_id);
    }

    public function test_recurring_command_is_idempotent_across_double_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00'));
        $c = $this->ctx();

        $this->withToken($c['token'])->postJson('/api/subscriptions', [
            'patient_id' => $c['patient_id'], 'plan_name' => 'DPC Basic',
            'amount_cents' => 7500, 'anchor_day' => 1,
        ])->assertCreated();

        Artisan::call('billing:run-recurring');
        Artisan::call('billing:run-recurring');

        $this->assertSame(1, DB::table('invoices')->count());
    }

    public function test_inactive_subscriptions_are_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00'));
        $c = $this->ctx();

        $id = $this->withToken($c['token'])->postJson('/api/subscriptions', [
            'patient_id' => $c['patient_id'], 'plan_name' => 'DPC Basic',
            'amount_cents' => 7500, 'anchor_day' => 1,
        ])->assertCreated()->json('id');
        $this->withToken($c['token'])->patchJson("/api/subscriptions/{$id}", ['active' => false])->assertOk();

        Artisan::call('billing:run-recurring');

        $this->assertSame(0, DB::table('invoices')->count());
    }
}
