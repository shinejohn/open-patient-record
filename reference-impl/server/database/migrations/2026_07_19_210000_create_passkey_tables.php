<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('credential_id', 512)->unique(); // base64url
            // Full serialized CredentialRecord (public key, counter, transports…).
            $table->text('record');
            $table->bigInteger('sign_count')->default(0);
            $table->string('nickname')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('webauthn_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 16); // register | login
            $table->text('options'); // serialized creation/request options (single-use)
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });

        Schema::create('recovery_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_tokens');
        Schema::dropIfExists('webauthn_challenges');
        Schema::dropIfExists('user_credentials');
    }
};
