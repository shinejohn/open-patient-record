<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E2: scheduling + encounters. SOAP fields are encrypted casts (text columns).
 * A signed encounter is locally immutable — the clinical record of note lives
 * in the vault (Encounter + DocumentReference committed as one transaction
 * Bundle at sign time); these rows are the practice-ops working copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->foreignUuid('clinician_id')->nullable()->constrained('users');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status')->default('booked'); // booked | cancelled
            $table->text('reason')->nullable();          // encrypted cast
            $table->timestampsTz();

            $table->index(['practice_id', 'starts_at']);
        });

        Schema::create('encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->foreignUuid('clinician_id')->constrained('users');
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments');
            $table->string('status')->default('draft'); // draft | signed
            $table->text('subjective')->nullable();     // encrypted casts
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('signed_at')->nullable();
            $table->string('vault_encounter_entry_id')->nullable();
            $table->string('vault_note_entry_id')->nullable();
            $table->timestampsTz();

            $table->index(['practice_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('appointments');
    }
};
