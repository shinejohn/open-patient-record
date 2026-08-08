<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 remainder: DPC recurring billing. A subscription bills a fixed amount on
 * a monthly cadence. `billing:run-recurring` generates invoices into the
 * SAME cash-pay ledger as manual superbills — no card processing, no new
 * money path — so `invoices.encounter_id` becomes nullable (a recurring
 * invoice has no encounter) and gains `subscription_id` + `period_key`. The
 * unique index on (subscription_id, period_key) is the idempotency guarantee:
 * running the generator twice for the same period cannot double-bill, at the
 * database layer, not just in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->string('plan_name');
            $table->integer('amount_cents');
            $table->string('interval')->default('monthly');
            $table->smallInteger('anchor_day')->default(1); // 1-28
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['practice_id', 'active']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUuid('encounter_id')->nullable()->change();
            $table->foreignUuid('subscription_id')->nullable()->after('encounter_id')->constrained('subscriptions');
            $table->string('period_key')->nullable()->after('subscription_id'); // e.g. '2026-08'
            $table->unique(['subscription_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['subscription_id', 'period_key']);
            $table->dropForeign(['subscription_id']);
            $table->dropColumn(['subscription_id', 'period_key']);
            $table->foreignUuid('encounter_id')->nullable(false)->change();
        });

        Schema::dropIfExists('subscriptions');
    }
};
