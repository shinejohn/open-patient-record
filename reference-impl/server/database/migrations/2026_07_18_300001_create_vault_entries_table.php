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
        Schema::create('vault_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vault_id')->constrained('vaults')->restrictOnDelete();
            // Per-vault sequence number; the chain is ordered by this.
            $table->bigInteger('seq');
            // FHIR R4 resource type of the payload (Condition, MedicationStatement, ...).
            $table->string('resource_type', 64);
            $table->jsonb('payload');
            // Spec §4.2: verified-source | clinician-verified | unverified-import
            $table->string('verification_tier', 32);
            // Spec §3.2: non-null means extra-protected; unknown values are treated as sensitive.
            $table->string('sensitive_category', 64)->nullable();
            // Spec §2.4: corrections supersede, never mutate. (FK added below — a
            // self-referencing FK inside create runs before the PK constraint exists.)
            $table->uuid('replaces_entry_id')->nullable();
            // SHA-256 of the canonical payload serialization.
            $table->string('content_hash', 64);
            // SHA-256(prev_chain_hash || content_hash) — the tamper-evidence chain (spec §4.2).
            $table->string('chain_hash', 64);
            $table->foreignUuid('contributor_user_id')->constrained('users')->restrictOnDelete();
            // Spec §5: mandatory provenance (org, author, source system, method, verifier).
            $table->jsonb('provenance');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['vault_id', 'seq']);
            $table->index(['vault_id', 'resource_type']);
        });

        Schema::table('vault_entries', function (Blueprint $table) {
            $table->foreign('replaces_entry_id')->references('id')->on('vault_entries')->restrictOnDelete();
        });

        // Append-only enforcement at the database layer (spec §4.1).
        // Layer 1: row trigger blocks UPDATE/DELETE. Layer 2: statement trigger blocks
        // TRUNCATE (row triggers do NOT fire on TRUNCATE — that hole is the reason this
        // layer exists). Layers 3 (RESTRICT FKs above) and 4 (model guards) complete the set.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION opr_append_only_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'OPR: % is append-only (spec §4.1); supersede instead of mutating', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS vault_entries_immutable ON vault_entries');
        DB::statement(<<<'SQL'
            CREATE TRIGGER vault_entries_immutable
            BEFORE UPDATE OR DELETE ON vault_entries
            FOR EACH ROW EXECUTE FUNCTION opr_append_only_guard();
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS vault_entries_no_truncate ON vault_entries');
        DB::statement(<<<'SQL'
            CREATE TRIGGER vault_entries_no_truncate
            BEFORE TRUNCATE ON vault_entries
            FOR EACH STATEMENT EXECUTE FUNCTION opr_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_entries');
        // Function is shared by later audit migration only within this schema's lifetime;
        // safe to drop here because down() runs in reverse order (audit drops first).
        DB::statement('DROP FUNCTION IF EXISTS opr_append_only_guard()');
    }
};
