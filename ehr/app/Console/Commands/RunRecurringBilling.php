<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F4 remainder: DPC recurring billing. One invoice per active subscription
 * per due period, landed in the SAME cash-pay ledger as a manual superbill —
 * no card processing, no new money path.
 *
 * Idempotency is a DATABASE guarantee: `invoices` has a unique index on
 * (subscription_id, period_key). Running this command twice for the same
 * period cannot double-bill, even under concurrent/duplicate invocation —
 * `insertOrIgnore` relies on that constraint, not an application-level check.
 */
final class RunRecurringBilling extends Command
{
    protected $signature = 'billing:run-recurring';

    protected $description = 'Generate one invoice per active DPC subscription for the current billing period.';

    public function handle(): int
    {
        $periodKey = now()->format('Y-m');
        $generated = 0;

        Subscription::query()->where('active', true)->chunkById(100, function ($subscriptions) use ($periodKey, &$generated): void {
            foreach ($subscriptions as $subscription) {
                $inserted = DB::table('invoices')->insertOrIgnore([[
                    'id' => (string) Str::uuid(),
                    'practice_id' => $subscription->practice_id,
                    'patient_id' => $subscription->patient_id,
                    'encounter_id' => null,
                    'subscription_id' => $subscription->id,
                    'period_key' => $periodKey,
                    'status' => 'open',
                    'total_cents' => $subscription->amount_cents,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
                $generated += $inserted;
            }
        });

        $this->info("Generated {$generated} recurring invoice(s) for period {$periodKey}.");

        return self::SUCCESS;
    }
}
