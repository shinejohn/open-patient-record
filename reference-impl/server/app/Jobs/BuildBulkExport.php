<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportJob;
use App\Models\VaultEntry;
use App\Services\FhirMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a Bulk-FHIR-compatible export: one ndjson file per resource type plus an
 * OPR metadata file (hashes, tiers, provenance, supersession) and the chain head —
 * so a recombined bulk export is also a valid §7.2 import source.
 *
 * Streaming discipline: entries are iterated with a cursor and written line by
 * line through file handles. The vault is never materialized in memory — a test
 * seeds 1,000 entries and asserts the memory bound.
 */
final class BuildBulkExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $exportJobId)
    {
    }

    public function handle(FhirMapper $fhir): void
    {
        /** @var ExportJob|null $job */
        $job = ExportJob::query()->find($this->exportJobId);
        if ($job === null || $job->status === 'cancelled') {
            return;
        }

        $job->forceFill(['status' => 'running'])->save();

        $disk = Storage::disk('local');
        $dir = $job->directory();
        $disk->makeDirectory($dir);
        $base = $disk->path($dir);

        /** @var array<string, resource> $handles */
        $handles = [];
        /** @var array<string, int> $counts */
        $counts = [];

        try {
            $metaHandle = fopen("{$base}/opr-metadata.ndjson", 'wb');
            $processed = 0;

            $query = VaultEntry::query()
                ->where('vault_id', $job->vault_id)
                ->orderBy('seq');

            foreach ($query->cursor() as $entry) {
                $type = $entry->resource_type;

                if (! isset($handles[$type])) {
                    $handles[$type] = fopen("{$base}/{$type}.ndjson", 'wb');
                    $counts[$type] = 0;
                }

                fwrite($handles[$type], json_encode($fhir->toResource($entry), JSON_UNESCAPED_SLASHES)."\n");
                $counts[$type]++;

                fwrite($metaHandle, json_encode([
                    'id' => $entry->id,
                    'seq' => $entry->seq,
                    'resource_type' => $type,
                    'verification_tier' => $entry->verification_tier,
                    'sensitive_category' => $entry->sensitive_category,
                    'replaces_entry_id' => $entry->replaces_entry_id,
                    'content_hash' => $entry->content_hash,
                    'chain_hash' => $entry->chain_hash,
                    'provenance' => $entry->provenance,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ], JSON_UNESCAPED_SLASHES)."\n");

                $processed++;
                if ($processed % 100 === 0) {
                    $job->forceFill(['progress' => $processed])->save();
                }
            }

            fclose($metaHandle);
            foreach ($handles as $h) {
                fclose($h);
            }

            $vault = $job->vault;
            $manifest = [
                'transactionTime' => now()->toIso8601String(),
                'request' => "Patient/\$export (vault {$vault->id})",
                'requiresAccessToken' => true,
                'output' => collect($counts)->map(fn (int $count, string $type) => [
                    'type' => $type,
                    'url' => url("/api/fhir/{$vault->id}/\$export-file/{$job->id}/{$type}.ndjson"),
                    'count' => $count,
                ])->values()->all(),
                'error' => [],
                'extension' => [
                    'opr-metadata-url' => url("/api/fhir/{$vault->id}/\$export-file/{$job->id}/opr-metadata.ndjson"),
                    'opr-chain-head-hash' => $vault->chain_head_hash,
                    'opr-entry-count' => $processed,
                ],
            ];

            $job->forceFill([
                'status' => 'complete',
                'progress' => $processed,
                'manifest' => $manifest,
                'expires_at' => now()->addHours(24),
            ])->save();
        } catch (\Throwable $e) {
            foreach ($handles as $h) {
                if (is_resource($h)) {
                    fclose($h);
                }
            }
            $job->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            throw $e;
        }
    }
}
