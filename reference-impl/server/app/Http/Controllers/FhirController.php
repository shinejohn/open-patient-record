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

        $resources = $this->currentView($vault, $grant)
            ->get()
            ->map(fn (VaultEntry $e) => $this->fhir->toResource($e))
            ->all();

        array_unshift($resources, $this->fhir->subjectPatient($vault));

        $this->auditRead($request, $vault, $grant, 'Patient/$everything', count($resources));

        return response()->json($this->fhir->searchsetBundle($this->baseUrl($vault), $resources));
    }

    /** GET /api/fhir/{vault}/{type} — type-level search (current view). */
    public function search(Request $request, Vault $vault, string $type): JsonResponse
    {
        $this->assertResourceType($type);
        $grant = $this->authorizeRead($request, $vault);

        $resources = $this->currentView($vault, $grant)
            ->where('resource_type', $type)
            ->get()
            ->map(fn (VaultEntry $e) => $this->fhir->toResource($e))
            ->all();

        $this->auditRead($request, $vault, $grant, $type, count($resources));

        return response()->json($this->fhir->searchsetBundle($this->baseUrl($vault), $resources));
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

        if (! \App\Services\FhirResourceRegistry::isSupported($type)) {
            return response()->json($this->fhir->operationOutcome(
                'not-supported',
                "Resource type '{$type}' is not supported on this server. See /fhir/metadata for the supported set.",
            ), 400);
        }

        $payload = $request->json()->all();
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

        // Authorization mirrors the native envelope path exactly.
        $grant = $this->currentGrant($request);
        if ($grant === null) {
            $this->assertSubject($request, $vault);
        } else {
            $this->assertGrantCovers($request, $grant, $vault, 'write');
            if (! $grant->coversResourceType($type)) {
                abort(403, 'forbidden');
            }
        }

        // Extract an incoming sensitive-category tag, then strip server-owned
        // fields: id and meta are assigned/decorated by the server, never trusted.
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

        try {
            $entry = $this->vaults->commitEntry($vault, $request->user(), [
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
        } catch (\InvalidArgumentException $e) {
            return response()->json($this->fhir->operationOutcome('invalid', $e->getMessage()), 422);
        }

        return response()
            ->json($this->fhir->toResource($entry), 201)
            ->header('Location', url("/api/fhir/{$vault->id}/{$type}/{$entry->id}"));
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
