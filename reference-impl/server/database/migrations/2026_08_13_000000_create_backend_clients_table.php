<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMART Backend Services (system-to-system, unattended). A backend client is
 * registered against exactly ONE vault — every FHIR base in this server is a
 * single vault, so a system client's authority is custody-scoped the same way
 * every other credential here is. The client authenticates with a private-key
 * JWT (client_assertion); we only ever need its PUBLIC key, stored as a JWK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backend_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            $table->string('name');
            // Public key material only — registered directly as a JWK (n/e), no
            // outbound fetch of a jwks_uri (SSRF surface); a URL is stored for
            // display/reference only and is never dereferenced by this server.
            $table->jsonb('jwk');
            $table->string('jwks_uri', 2048)->nullable();
            // Resource types this client may request under system/*.read; '*' allowed.
            $table->jsonb('scope');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        // Replay defense for client_assertion JWTs (the `jti` claim must be used
        // once). Rows are disposable once expires_at passes; a scheduled prune is
        // out of scope for this pass (a small, bounded table).
        Schema::create('backend_client_assertions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('backend_clients')->cascadeOnDelete();
            $table->string('jti');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->unique(['client_id', 'jti']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backend_client_assertions');
        Schema::dropIfExists('backend_clients');
    }
};
