<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E1: the practice's patient roster — the ProviderCopy side of custody.
 *
 * The clinical record does NOT live here; it lives in the patient's vault,
 * reached over the public OPR/FHIR API under a treatment AccessGrant. This
 * table holds practice-ops linkage plus contact demographics, with direct
 * identifiers and the grant token stored via encrypted casts (text columns).
 * There is deliberately NO subject-credential column: the practice holds a
 * revocable grant, never the patient's own key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->string('vault_id');
            $table->string('vault_user_id');
            $table->string('grant_pseudo_id');
            $table->text('grant_token');      // encrypted cast
            $table->text('name');             // encrypted cast
            $table->text('email')->nullable();      // encrypted cast
            $table->text('birth_date')->nullable(); // encrypted cast
            $table->string('gender', 32)->nullable();
            $table->timestampsTz();

            $table->index(['practice_id']);
            $table->unique(['practice_id', 'vault_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
