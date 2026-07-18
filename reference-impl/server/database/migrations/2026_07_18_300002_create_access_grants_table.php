<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            // Random public handle — never derived from any identifier (spec §3.3, no oracle).
            $table->string('pseudo_id', 40)->unique();
            // Only the HASH of the one-time secret is stored; plaintext is returned exactly once.
            $table->string('otp_hash');
            // Spec §3.1: treatment | personal-share | research | emergency | operations.
            $table->string('purpose', 32);
            // FHIR resource types this grant may see, or ["*"].
            $table->jsonb('scope');
            // ["read"] or ["read","write"].
            $table->jsonb('permissions');
            // Sensitive categories EXPLICITLY included (spec §3.2: default is excluded).
            $table->jsonb('sensitive_categories');
            $table->timestampTz('expires_at');
            $table->integer('max_uses')->default(1);
            $table->integer('uses')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->text('emergency_reason')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
