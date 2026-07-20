<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Bulk FHIR $export: async pattern, streamed ndjson, memory-bounded, import-compatible. */
final class BulkExportTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{s: array, manifest: array} */
    private function exportedVault(int $extraEntries = 0, string $email = 'subject@example.test'): array
    {
        $s = $this->subjectWithVault($email);
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Metformin']],
        ])->assertCreated();

        if ($extraEntries > 0) {
            // Seed through the real service (API loop would be slow at 1k).
            $vault = Vault::query()->findOrFail($s['vault_id']);
            $subject = User::query()->findOrFail($s['user_id']);
            $service = app(VaultService::class);
            for ($i = 0; $i < $extraEntries; $i++) {
                $service->commitEntry($vault->refresh(), $subject, [
                    'resource_type' => 'Observation',
                    'payload' => ['resourceType' => 'Observation', 'code' => ['text' => "Obs {$i}"], 'valueQuantity' => ['value' => $i]],
                    'verification_tier' => 'verified-source',
                    'provenance' => ['organization' => 'Bulk Clinic'],
                ]);
            }
        }

        $kick = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$export")
            ->assertStatus(202);
        $this->assertNotEmpty($kick->headers->get('Content-Location'));

        // QUEUE_CONNECTION=sync in tests: job already ran; status returns the manifest.
        $status = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/\$export-status/{$kick->json('export_job_id')}")
            ->assertOk();

        return ['s' => $s, 'manifest' => $status->json(), 'job' => $kick->json('export_job_id')];
    }

    public function test_async_flow_produces_a_complete_manifest(): void
    {
        ['manifest' => $m] = $this->exportedVault();

        $this->assertTrue($m['requiresAccessToken']);
        $types = array_column($m['output'], 'type');
        $this->assertEqualsCanonicalizing(['Condition', 'MedicationStatement'], $types);
        $this->assertSame(2, $m['extension']['opr-entry-count']);
        $this->assertNotEmpty($m['extension']['opr-chain-head-hash']);
    }

    public function test_files_are_valid_ndjson_and_metadata_is_complete(): void
    {
        ['s' => $s, 'job' => $job] = $this->exportedVault();

        $body = $this->withToken($s['token'])
            ->get("/api/fhir/{$s['vault_id']}/\$export-file/{$job}/Condition.ndjson")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/fhir+ndjson')
            ->streamedContent();

        $lines = array_filter(explode("\n", $body));
        $this->assertCount(1, $lines);
        $resource = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Condition', $resource['resourceType']);
        $this->assertSame('urn:opr:verification-tier', $resource['meta']['tag'][0]['system']);

        $meta = $this->withToken($s['token'])
            ->get("/api/fhir/{$s['vault_id']}/\$export-file/{$job}/opr-metadata.ndjson")
            ->assertOk()
            ->streamedContent();
        $metaLines = array_filter(explode("\n", $meta));
        $this->assertCount(2, $metaLines); // one per entry: hashes, tier, provenance
        $first = json_decode($metaLines[0], true, 512, JSON_THROW_ON_ERROR);
        foreach (['content_hash', 'chain_hash', 'verification_tier', 'provenance', 'seq'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }

    public function test_streaming_is_memory_bounded_at_one_thousand_entries(): void
    {
        $before = memory_get_peak_usage(true);
        ['manifest' => $m] = $this->exportedVault(extraEntries: 1000);
        $delta = memory_get_peak_usage(true) - $before;

        $this->assertSame(1002, $m['extension']['opr-entry-count']);
        // Cursor + line-by-line writes: the vault must never materialize in memory.
        $this->assertLessThan(64 * 1024 * 1024, $delta, 'export materialized the vault in memory');
    }

    public function test_only_subject_or_delegate_may_export_and_download(): void
    {
        ['s' => $s, 'job' => $job] = $this->exportedVault();
        $stranger = $this->registerUser('stranger@example.test');

        $this->withToken($stranger['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$export")
            ->assertForbidden();
        $this->withToken($stranger['token'])
            ->get("/api/fhir/{$s['vault_id']}/\$export-file/{$job}/Condition.ndjson")
            ->assertForbidden();

        // Grant tokens never bulk-export.
        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();
        $grantToken = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'), 'otp' => $mint->json('otp'),
        ])->assertOk()->json('token');
        $this->withToken($grantToken)
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$export")
            ->assertForbidden();
    }

    public function test_cancel_removes_files_and_expiry_cleanup_works(): void
    {
        ['s' => $s, 'job' => $job] = $this->exportedVault();

        $this->withToken($s['token'])
            ->deleteJson("/api/fhir/{$s['vault_id']}/\$export-status/{$job}")
            ->assertOk();
        $this->withToken($s['token'])
            ->get("/api/fhir/{$s['vault_id']}/\$export-file/{$job}/Condition.ndjson")
            ->assertNotFound();

        // Expiry: a fresh export, aged past 24h, is reaped by the scheduled command.
        ['s' => $s2, 'job' => $job2] = $this->exportedVault(email: 'subject2@example.test');
        $this->travel(25)->hours();
        $this->artisan('opr:cleanup-exports')->assertSuccessful();
        $this->withToken($s2['token'])
            ->getJson("/api/fhir/{$s2['vault_id']}/\$export-status/{$job2}")
            ->assertNotFound();
    }

    public function test_path_traversal_shapes_are_rejected(): void
    {
        ['s' => $s, 'job' => $job] = $this->exportedVault();

        $this->withToken($s['token'])
            ->get("/api/fhir/{$s['vault_id']}/\$export-file/{$job}/..%2F..%2Fetc%2Fpasswd")
            ->assertNotFound();
    }
}
