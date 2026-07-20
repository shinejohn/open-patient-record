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
        Schema::create('vault_delegates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            $table->foreignUuid('delegate_user_id')->constrained('users')->restrictOnDelete();
            // guardian (parent/legal guardian) | proxy (patient-chosen helper)
            $table->string('role', 32);
            $table->foreignUuid('added_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        // One ACTIVE delegation per (vault, user); re-delegation after revocation is
        // allowed — hence a partial unique index, not a full one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX vault_delegates_active_unique
            ON vault_delegates (vault_id, delegate_user_id)
            WHERE revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_delegates');
    }
};
