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
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('grant_id')->nullable()->constrained('access_grants')->restrictOnDelete();
            // entry.committed | entries.read | export | verify | grant.minted | grant.redeemed
            // | grant.denied | grant.revoked
            $table->string('action', 40);
            $table->string('purpose', 32)->nullable();
            $table->boolean('is_emergency')->default(false);
            $table->text('reason')->nullable();
            // Identifiers only — never clinical values (audit rows must not become PHI leaks).
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['vault_id', 'created_at']);
        });

        // Same append-only defense as vault_entries (spec §6).
        DB::statement('DROP TRIGGER IF EXISTS audit_events_immutable ON audit_events');
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_events_immutable
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION opr_append_only_guard();
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events');
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_events_no_truncate
            BEFORE TRUNCATE ON audit_events
            FOR EACH STATEMENT EXECUTE FUNCTION opr_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
