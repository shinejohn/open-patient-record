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
