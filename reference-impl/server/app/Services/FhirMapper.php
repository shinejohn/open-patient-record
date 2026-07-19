<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vault;
use App\Models\VaultEntry;

/**
 * Maps vault entries onto the FHIR R4 read surface. Clinical content is stored AS
 * FHIR payloads, so mapping is decoration, not translation: we stamp id, meta
 * (versionId = vault sequence, lastUpdated = commit time), and OPR tags.
 *
 * meta.tag is FHIR's native filtering mechanism — consumers exclude
 * unverified-import entries from decision support by tag (spec §4.3).
 * TODO(naming): urn:opr:* systems become proper canonical URLs once the public
 * domain is settled.
 */
final class FhirMapper
{
    public const TIER_SYSTEM = 'urn:opr:verification-tier';
    public const SENSITIVE_SYSTEM = 'urn:opr:sensitive-category';

    /** @return array<string, mixed> */
    public function toResource(VaultEntry $entry): array
    {
        $resource = $entry->payload;
        $resource['resourceType'] = $entry->resource_type;
        $resource['id'] = $entry->id;

        $tags = [[
            'system' => self::TIER_SYSTEM,
            'code' => $entry->verification_tier,
        ]];
        if ($entry->sensitive_category !== null) {
            $tags[] = [
                'system' => self::SENSITIVE_SYSTEM,
                'code' => $entry->sensitive_category,
            ];
        }

        $resource['meta'] = array_replace($resource['meta'] ?? [], [
            'versionId' => (string) $entry->seq,
            'lastUpdated' => $entry->created_at?->toIso8601String(),
            'tag' => $tags,
        ]);

        return $resource;
    }

    /**
     * Minimal Patient resource for the vault subject. The vault server is not
     * demographics-authoritative in M2 — richer Patient data belongs in committed
     * Patient entries; this synthesized resource is the anchor for $everything.
     *
     * @return array<string, mixed>
     */
    public function subjectPatient(Vault $vault): array
    {
        return [
            'resourceType' => 'Patient',
            'id' => $vault->id,
            'name' => [['text' => $vault->subject->name]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    public function searchsetBundle(string $baseUrl, array $resources): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => count($resources),
            'entry' => array_map(fn (array $r) => [
                'fullUrl' => "{$baseUrl}/{$r['resourceType']}/{$r['id']}",
                'resource' => $r,
            ], $resources),
        ];
    }

    /** @return array<string, mixed> */
    public function capabilityStatement(): array
    {
        return [
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => now()->toIso8601String(),
            'kind' => 'instance',
            'fhirVersion' => '4.0.1',
            'format' => ['json'],
            'software' => ['name' => 'OPR Vault Server (reference implementation)'],
            'implementation' => [
                'description' => 'Per-vault FHIR R4 read surface. Base: /api/fhir/{vault}. '
                    .'Write path is the OPR commit API; committed content is append-only '
                    .'(update/delete return OperationOutcome per OPR spec §4.1).',
            ],
            'rest' => [[
                'mode' => 'server',
                'documentation' => 'Read-only. Access requires the vault subject\'s token or a '
                    .'redeemed OPR AccessGrant token; scope and sensitive-category filtering '
                    .'apply per grant. Entries tagged '.self::TIER_SYSTEM.'|unverified-import '
                    .'MUST be excluded from clinical decision support (OPR spec §4.3).',
                'interaction' => [['code' => 'search-system']],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function operationOutcome(string $code, string $diagnostics): array
    {
        return [
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity' => 'error',
                'code' => $code,
                'diagnostics' => $diagnostics,
            ]],
        ];
    }
}
