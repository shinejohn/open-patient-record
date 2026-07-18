<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaults', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One person, one vault. RESTRICT: you cannot delete a user who owns a vault.
            $table->foreignUuid('subject_user_id')->unique()->constrained('users')->restrictOnDelete();
            // Chain head: the newest chain hash — a single value summarizing the whole history (spec §4.5).
            $table->string('chain_head_hash', 64)->nullable();
            $table->bigInteger('entry_count')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};
