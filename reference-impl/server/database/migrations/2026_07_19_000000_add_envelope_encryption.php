<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            // Per-vault DEK, wrapped by the master key. Nullable: lazily generated
            // for vaults created before this migration.
            $table->text('wrapped_dek')->nullable();
        });

        // Payload becomes ciphertext (text). DDL does not fire row triggers, so the
        // append-only guard is unaffected; existing JSON rows stay readable via the
        // plain-JSON fallback in EnvelopeCrypto::decrypt().
        DB::statement('ALTER TABLE vault_entries ALTER COLUMN payload TYPE text USING payload::text');
    }

    public function down(): void
    {
        // Only lossless if every row is still plain JSON; encrypted rows cannot be
        // reverted to jsonb without the keys — by design.
        DB::statement('ALTER TABLE vault_entries ALTER COLUMN payload TYPE jsonb USING payload::jsonb');
        Schema::table('vaults', function (Blueprint $table) {
            $table->dropColumn('wrapped_dek');
        });
    }
};
