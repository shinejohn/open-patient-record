<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\AccessGrant;
use App\Models\Vault;
use App\Models\VaultEntry;
use App\Services\AuditLogger;
use App\Services\FhirMapper;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-vault FHIR R4 read surface: each vault is its own FHIR base URL
 * (/api/fhir/{vault}). Read-only by design — writes go through the OPR commit API,
 * and mutation attempts get the spec §4.1 OperationOutcome.
 *
 * FHIR reads return the CURRENT view: superseded entries are excluded (FHIR
 * consumers expect current state). Full history remains available via the native
 * entries API and the portability export.
 */
final class FhirController
{
    use ResolvesGrantTokens;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly FhirMapper $fhir,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Server metadata — unauthenticated per FHIR convention; carries no PHI. */
    public function metadata(): JsonResponse
    {
        return response()->json($this->fhir->capabilityStatement());
    }

    /** GET /api/fhir/{vault}/Patient/$everything — the complete visible record. */
    public function everything(Request $request, Vault $vault): JsonResponse
    {
        $grant = $this->authorizeRead($request, $vault);

        $entries = $this->currentView($vault, $grant)->get();

        $resources = $entries
            ->filter(fn (VaultEntry $e): bool => $e->resource_type !== 'Patient')
            ->map(fn (VaultEntry $e) => $this->fhir->toResource($e))
            ->values()
            ->all();

        // A committed Patient entry (current view, newest) supplies the anchor's
        // demographics; the anchor keeps the vault id as the join point.
        $patientEntry = $entries
            ->filter(fn (VaultEntry $e): bool => $e->resource_type === 'Patient')
            ->sortByDesc('seq')
            ->first();

        array_unshift($resources, $this->fhir->subjectPatient($vault, $patientEntry?->payload));

        $this->auditRead($request, $vault, $grant, 'Patient/$everything', count($resources));

        return response()->json($this->fhir->searchsetBundle($this->baseUrl($vault), $resources));
    }

    /**
     * GET /api/fhir/{vault}/{type} — type-level search over the current view,
     * with registry-declared parameters and _count/_offset pagination.
     * `total` is the total match count; `entry` is the requested page.
     */
    public function search(Request $request, Vault $vault, string $type): JsonResponse
    {
        $this->assertResourceType($type);
        $grant = $this->authorizeRead($request, $vault);

        $entries = $this->currentView($vault, $grant)
            ->where('resource_type', $type)
            ->get()
            ->all();

        $result = app(\App\Services\FhirSearchEngine::class)->apply($entries, $type, $request->query());
        $total = count($result['matches']);

        $count = max(1, min(100, (int) $request->query('_count', '50')));
        $offset = max(0, (int) $request->query('_offset', '0'));
        $page = array_slice($result['matches'], $offset, $count);

        $resources = array_map(fn (VaultEntry $e) => $this->fhir->toResource($e), $page);

        $base = "{$this->baseUrl($vault)}/{$type}";
        $linkQuery = $result['applied'] + ['_count' => (string) $count];
        $links = [[
            'relation' => 'self',
            'url' => $base.'?'.http_build_query($linkQuery + ['_offset' => (string) $offset]),
        ]];
        if ($offset + $count < $total) {
            $links[] = [
                'relation' => 'next',
                'url' => $base.'?'.http_build_query($linkQuery + ['_offset' => (string) ($offset + $count)]),
            ];
        }

        $this->auditRead($request, $vault, $grant, $type, count($resources));

        return response()->json(
            $this->fhir->searchsetBundle($this->baseUrl($vault), $resources, $total, $links),
        );
    }

    /** GET /api/fhir/{vault}/{type}/{id} — single resource read. */
    public function read(Request $request, Vault $vault, string $type, string $id): JsonResponse
    {
        $this->assertResourceType($type);
        $grant = $this->authorizeRead($request, $vault);

        /** @var VaultEntry|null $entry */
        $entry = $this->vaults->visibleEntries($vault, $grant)
            ->where('resource_type', $type)
            ->whereKey($id)
            ->first();

        if ($entry === null) {
            // Invisible and nonexistent are indistinguishable — no oracle.
            return response()->json(
                $this->fhir->operationOutcome('not-found', 'Resource not found.'),
                404,
            );
        }

        $this->auditRead($request, $vault, $grant, "{$type}/{$id}", 1);

        return response()->json($this->fhir->toResource($entry));
    }

    /**
     * POST /api/fhir/{vault}/{type} — FHIR create (F1).
     *
     * The strict door onto the one write path (VaultService::commitEntry): every
     * FHIR create is hash-chained, audited, append-only, provenance-carrying.
     *
     * - Registry-supported types only; R4 required elements enforced.
     * - Server assigns the id; client-asserted id/meta.versionId are ignored.
     * - Tier derives from the actor: subject/delegate hand → unverified-import
     *   (excluded from CDS until clinician-verified, spec §4.3); grant-holding
     *   system → verified-source.
     * - An incoming urn:opr:sensitive-category meta.tag becomes the entry's
     *   sensitive_category; existing grant filtering applies on read.
     * - Contributing organization: X-OPR-Organization header when present,
     *   otherwise derived from the actor (grant handle / self-entry).
     */
    public function create(Request $request, Vault $vault, string $type): JsonResponse
    {
        $this->assertResourceType($type);

        $payload = $request->json()->all();
        if (($error = $this->validateFhirResource($type, $payload)) !== null) {
            return $error;
        }

        $grant = $this->authorizeFhirWrite($request, $vault, $type);

        try {
            $entry = $this->commitFhirResource($request, $vault, $grant, $type, $payload);
        } catch (\InvalidArgumentException $e) {
            return response()->json($this->fhir->operationOutcome('invalid', $e->getMessage()), 422);
        }

        return response()
            ->json($this->fhir->toResource($entry), 201)
            ->header('Location', url("/api/fhir/{$vault->id}/{$type}/{$entry->id}"));
    }

    /**
     * POST /api/fhir/{vault} — transaction and batch Bundles (F1).
     *
     * transaction = all-or-nothing: every entry is validated and authorized
     * BEFORE anything commits, and the commits run inside one DB transaction —
     * the chain never carries half a transaction. batch = independent entries,
     * per-entry statuses. Only POST entries exist here: committed content is
     * append-only, so PUT/DELETE entries are refused (spec §4.1).
     */
    public function bundle(Request $request, Vault $vault): JsonResponse
    {
        $body = $request->json()->all();
        $mode = $body['type'] ?? null;
        if (($body['resourceType'] ?? null) !== 'Bundle' || ! in_array($mode, ['transaction', 'batch'], true)) {
            return response()->json($this->fhir->operationOutcome(
                'invalid',
                "Body must be a Bundle of type 'transaction' or 'batch'.",
            ), 400);
        }

        // Normalize entries: [type, payload, error?]
        $items = [];
        foreach ($body['entry'] ?? [] as $entry) {
            $method = strtoupper((string) ($entry['request']['method'] ?? ''));
            $type = explode('/', (string) ($entry['request']['url'] ?? ''), 2)[0];
            $payload = $entry['resource'] ?? [];

            if ($method !== 'POST') {
                $items[] = ['type' => $type, 'payload' => $payload, 'error' => response()->json(
                    $this->fhir->operationOutcome(
                        'business-rule',
                        'Committed entries are append-only (OPR spec §4.1); only POST entries are accepted. Corrections are superseding entries.',
                    ),
                    405,
                )];
                continue;
            }

            $items[] = [
                'type' => $type,
                'payload' => $payload,
                'error' => $this->validateFhirResource($type, is_array($payload) ? $payload : []),
            ];
        }

        if ($mode === 'transaction') {
            // Validate everything before touching the vault.
            foreach ($items as $item) {
                if ($item['error'] !== null) {
                    return $item['error'];
                }
            }
            foreach (array_unique(array_column($items, 'type')) as $type) {
                $this->authorizeFhirWrite($request, $vault, $type); // aborts 403
            }

            $responses = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $vault, $items): array {
                $grant = $this->currentGrant($request);
                $out = [];
                foreach ($items as $item) {
                    $entry = $this->commitFhirResource($request, $vault, $grant, $item['type'], $item['payload']);
                    $out[] = [
                        'response' => ['status' => '201 Created', 'location' => url("/api/fhir/{$vault->id}/{$item['type']}/{$entry->id}")],
                        'resource' => $this->fhir->toResource($entry),
                    ];
                }

                return $out;
            });

            return response()->json([
                'resourceType' => 'Bundle',
                'type' => 'transaction-response',
                'entry' => $responses,
            ]);
        }

        // batch — each entry stands alone.
        $responses = [];
        foreach ($items as $item) {
            if ($item['error'] !== null) {
                $responses[] = [
                    'response' => ['status' => $item['error']->getStatusCode().' '.\Symfony\Component\HttpFoundation\Response::$statusTexts[$item['error']->getStatusCode()]],
                    'resource' => $item['error']->getData(true),
                ];
                continue;
            }

            try {
                $grant = $this->authorizeFhirWrite($request, $vault, $item['type']);
                $entry = $this->commitFhirResource($request, $vault, $grant, $item['type'], $item['payload']);
                $responses[] = [
                    'response' => ['status' => '201 Created', 'location' => url("/api/fhir/{$vault->id}/{$item['type']}/{$entry->id}")],
                    'resource' => $this->fhir->toResource($entry),
                ];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $responses[] = [
                    'response' => ['status' => $e->getStatusCode().' '.\Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()]],
                    'resource' => $this->fhir->operationOutcome('forbidden', 'Not authorized for this entry.'),
                ];
            } catch (\InvalidArgumentException $e) {
                $responses[] = [
                    'response' => ['status' => '422 Unprocessable Entity'],
                    'resource' => $this->fhir->operationOutcome('invalid', $e->getMessage()),
                ];
            }
        }

        return response()->json([
            'resourceType' => 'Bundle',
            'type' => 'batch-response',
            'entry' => $responses,
        ]);
    }

    /** Registry + structural validation shared by create() and bundle(). */
    private function validateFhirResource(string $type, array $payload): ?JsonResponse
    {
        if (preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $type) !== 1
            || ! \App\Services\FhirResourceRegistry::isSupported($type)) {
            return response()->json($this->fhir->operationOutcome(
                'not-supported',
                "Resource type '{$type}' is not supported on this server. See /fhir/metadata for the supported set.",
            ), 400);
        }

        if (($payload['resourceType'] ?? null) !== $type) {
            return response()->json($this->fhir->operationOutcome(
                'invalid',
                "Body resourceType must be '{$type}' to match the request URL.",
            ), 400);
        }

        $missing = \App\Services\FhirResourceRegistry::missingElements($type, $payload);
        if ($missing !== []) {
            return response()->json([
                'resourceType' => 'OperationOutcome',
                'issue' => array_map(static fn (string $path): array => [
                    'severity' => 'error',
                    'code' => 'required',
                    'diagnostics' => "Required element missing: {$path}",
                    'expression' => [$path],
                ], $missing),
            ], 422);
        }

        return null;
    }

    /** Authorization mirrors the native envelope path exactly. Aborts 403. */
    private function authorizeFhirWrite(Request $request, Vault $vault, string $type): ?AccessGrant
    {
        $grant = $this->currentGrant($request);
        if ($grant === null) {
            $this->assertSubject($request, $vault);
        } else {
            $this->assertGrantCovers($request, $grant, $vault, 'write');
            if (! $grant->coversResourceType($type)) {
                abort(403, 'forbidden');
            }
        }

        return $grant;
    }

    /**
     * The one FHIR-door commit: sensitive-tag intake, server-owned field
     * stripping, actor-derived tier and organization, then commitEntry — so
     * every FHIR write (single or bundled) is hash-chained, audited, and
     * provenance-carrying identically.
     *
     * @param array<string, mixed> $payload
     */
    private function commitFhirResource(Request $request, Vault $vault, ?AccessGrant $grant, string $type, array $payload): VaultEntry
    {
        $sensitiveCategory = null;
        foreach ($payload['meta']['tag'] ?? [] as $tag) {
            if (($tag['system'] ?? null) === FhirMapper::SENSITIVE_SYSTEM && isset($tag['code'])) {
                $sensitiveCategory = (string) $tag['code'];
            }
        }
        unset($payload['id'], $payload['meta']);

        $organization = trim((string) $request->header('X-OPR-Organization', ''));
        if ($organization === '') {
            $organization = $grant === null ? 'self-entry' : 'grant:'.$grant->pseudo_id;
        }

        return $this->vaults->commitEntry($vault, $request->user(), [
            'resource_type' => $type,
            'payload' => $payload,
            'verification_tier' => $grant === null ? 'unverified-import' : 'verified-source',
            'sensitive_category' => $sensitiveCategory,
            'provenance' => [
                'organization' => $organization,
                'author' => $request->user()->name,
                'source_system' => 'fhir-create',
            ],
        ], $grant);
    }

    /** Spec §4.1 on the FHIR surface: committed content rejects mutation. */
    public function reject(): JsonResponse
    {
        return response()->json($this->fhir->operationOutcome(
            'business-rule',
            'Committed entries are append-only (OPR spec §4.1). Commit a superseding entry (§2.4) via the OPR API instead of modifying or deleting.',
        ), 405);
    }

    // ---------------------------------------------------------------

    private function authorizeRead(Request $request, Vault $vault): ?AccessGrant
    {
        $grant = $this->currentGrant($request);

        if ($grant === null) {
            $this->assertSubject($request, $vault);
        } else {
            $this->assertGrantCovers($request, $grant, $vault, 'read');
        }

        return $grant;
    }

    /** Current view: visible entries minus those superseded by a later entry. */
    private function currentView(Vault $vault, ?AccessGrant $grant)
    {
        return $this->vaults->visibleEntries($vault, $grant)
            ->whereNotExists(function ($query) use ($vault): void {
                $query->selectRaw('1')
                    ->from('vault_entries as successors')
                    ->whereColumn('successors.replaces_entry_id', 'vault_entries.id')
                    ->where('successors.vault_id', $vault->id);
            });
    }

    private function assertResourceType(string $type): void
    {
        // FHIR resource type shape; storage itself is type-agnostic.
        if (preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $type) !== 1) {
            abort(404);
        }
    }

    private function auditRead(Request $request, Vault $vault, ?AccessGrant $grant, string $what, int $count): void
    {
        $this->audit->record($vault, 'entries.read', actor: $request->user(), grant: $grant, context: [
            'surface' => 'fhir',
            'target' => $what,
            'returned' => $count,
        ]);
    }

    private function baseUrl(Vault $vault): string
    {
        return url("/api/fhir/{$vault->id}");
    }
}
