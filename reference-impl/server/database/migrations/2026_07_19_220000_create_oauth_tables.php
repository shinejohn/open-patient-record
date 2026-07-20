<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('redirect_uri', 2048);
            $table->boolean('confidential')->default(false);
            $table->string('secret_hash')->nullable(); // confidential clients only
            $table->timestampsTz();
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained('access_grants')->restrictOnDelete();
            $table->string('code_hash');
            $table->string('code_challenge'); // PKCE S256, base64url
            $table->string('redirect_uri', 2048);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained('access_grants')->restrictOnDelete();
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
