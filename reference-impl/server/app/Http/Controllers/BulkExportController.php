<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Jobs\BuildBulkExport;
use App\Models\ExportJob;
use App\Models\Vault;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk FHIR $export via the FHIR Async Request pattern (kickoff 202 →
 * Content-Location status → manifest → per-type ndjson files).
 * Subject/delegate only — grant tokens never bulk-export.
 */
final class BulkExportController
{
    use ResolvesGrantTokens;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function kickoff(Request $request, Vault $vault): Response
    {
        $this->assertSubject($request, $vault);

        $job = ExportJob::query()->create([
            'vault_id' => $vault->id,
            'requested_by' => $request->user()->id,
        ]);

        BuildBulkExport::dispatch($job->id);

        $this->audit->record($vault, 'export', actor: $request->user(), context: [
            'mode' => 'bulk',
            'export_job_id' => $job->id,
        ]);

        return response()->json(['export_job_id' => $job->id], 202, [
            'Content-Location' => url("/api/fhir/{$vault->id}/\$export-status/{$job->id}"),
        ]);
    }

    public function status(Request $request, Vault $vault, ExportJob $job): Response
    {
        $this->assertSubject($request, $vault);
        $this->assertJobBelongs($job, $vault);

        return match (true) {
            $job->isExpired() => response()->json(['error' => 'expired'], 404),
            $job->status === 'complete' => response()->json($job->manifest, 200, [
                'Expires' => $job->expires_at?->toRfc7231String() ?? '',
            ]),
            $job->status === 'failed' => response()->json(['error' => 'export_failed'], 500),
            $job->status === 'cancelled' => response()->json(['error' => 'cancelled'], 404),
            default => response()->json(null, 202, ['X-Progress' => (string) $job->progress]),
        };
    }

    public function cancel(Request $request, Vault $vault, ExportJob $job): JsonResponse
    {
        $this->assertSubject($request, $vault);
        $this->assertJobBelongs($job, $vault);

        $job->forceFill(['status' => 'cancelled'])->save();
        Storage::disk('local')->deleteDirectory($job->directory());

        return response()->json(['cancelled' => true]);
    }

    public function file(Request $request, Vault $vault, ExportJob $job, string $file): Response
    {
        $this->assertSubject($request, $vault);
        $this->assertJobBelongs($job, $vault);

        // Filename allowlist shape: {ResourceType|opr-metadata}.ndjson — no traversal.
        if ($job->status !== 'complete' || $job->isExpired()
            || preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,63}\.ndjson\z/', $file) !== 1) {
            abort(404);
        }

        $path = $job->directory().'/'.$file;
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return new StreamedResponse(function () use ($path): void {
            $stream = Storage::disk('local')->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => 'application/fhir+ndjson']);
    }

    private function assertJobBelongs(ExportJob $job, Vault $vault): void
    {
        if ($job->vault_id !== $vault->id) {
            abort(404);
        }
    }
}
