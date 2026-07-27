<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E3: cash-pay billing + the honest transmission ledger.
 * Money is INTEGER CENTS everywhere — floats never touch a money column.
 * The ledger has no 'sent' state: nothing here can send electronically, so no
 * status may claim it (the honest-outbox law).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedule_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->string('cpt_code', 8);
            $table->string('description');
            $table->integer('price_cents');
            $table->timestampsTz();
            $table->unique(['practice_id', 'cpt_code']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->foreignUuid('encounter_id')->constrained('encounters');
            $table->string('status')->default('open'); // open | paid
            $table->integer('total_cents');
            $table->timestampsTz();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('cpt_code', 8);
            $table->string('icd10_code', 10)->nullable();
            $table->string('description');
            $table->integer('price_cents');
            $table->timestampsTz();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->integer('amount_cents');
            $table->string('method'); // cash | card | check | other
            $table->timestampTz('received_at');
            $table->timestampsTz();
        });

        Schema::create('transmissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->string('channel');          // print | fax — never 'electronic' here
            $table->string('kind');             // prescription | referral | records
            $table->text('summary');            // encrypted cast
            $table->string('status')->default('queued'); // queued | printed | fax_queued
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_schedule_items');
    }
};
