<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\VaultEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §4.1 and §6: append-only, defended at the database AND application layers. */
final class AppendOnlyTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_database_blocks_update_of_entries(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        DB::update("UPDATE vault_entries SET resource_type = 'Tampered'");
    }

    public function test_database_blocks_delete_of_entries(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        DB::delete('DELETE FROM vault_entries');
    }

    public function test_database_blocks_truncate_of_entries(): void
    {
        // Row triggers do NOT fire on TRUNCATE — this asserts the statement-trigger layer.
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        DB::statement('TRUNCATE vault_entries');
    }

    public function test_database_blocks_mutation_of_audit_events(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');
        DB::delete('DELETE FROM audit_events');
    }

    public function test_model_layer_refuses_entry_mutation(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $entry = VaultEntry::query()->firstOrFail();
        $this->expectException(RuntimeException::class);
        $entry->update(['resource_type' => 'Tampered']);
    }

    public function test_model_layer_refuses_audit_mutation(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $event = AuditEvent::query()->firstOrFail();
        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    public function test_api_rejects_entry_mutation_with_operation_outcome(): void
    {
        $s = $this->subjectWithVault();
        $entry = $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->withToken($s['token'])
                ->{$method}("/api/vaults/{$s['vault_id']}/entries/{$entry->json('id')}")
                ->assertStatus(405)
                ->assertJsonPath('resourceType', 'OperationOutcome');
        }
    }
}
