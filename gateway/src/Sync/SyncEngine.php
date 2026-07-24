<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

use Opr\Gateway\Candidate;
use Opr\Gateway\Parser\SensitiveClassifier;

/**
 * Continuous-sync engine for the OPR Legacy Gateway (build plan: parallel-run
 * EHR replacement). Repeatedly pulls clinical resources from an incumbent
 * FhirSource, classifies each as new/changed/unchanged/unresolved against
 * persisted state, and turns new+changed resources into Candidates for the
 * SAME human verification flow as a one-shot import (Verification::signOff).
 * Changed resources carry the prior vault entry id so the vault records a
 * superseding entry, not an unrelated duplicate — the chain stays honest.
 *
 * Sync never auto-commits: it only produces candidates. A clinician still
 * reviews and signs off, same as medication reconciliation for a file import.
 */
final class SyncEngine
{
    // Mirrors FhirBundleParser::RESOURCE_DOMAINS — kept independent so sync
    // classification is not coupled to the one-shot parser's internals.
    private const RESOURCE_DOMAINS = [
        'MedicationStatement' => Candidate::DOMAIN_MEDICATION,
        'MedicationRequest' => Candidate::DOMAIN_MEDICATION,
        'AllergyIntolerance' => Candidate::DOMAIN_ALLERGY,
        'Condition' => Candidate::DOMAIN_PROBLEM,
        'Immunization' => Candidate::DOMAIN_IMMUNIZATION,
        'Observation' => Candidate::DOMAIN_RESULT,
        'DiagnosticReport' => Candidate::DOMAIN_RESULT,
    ];

    public function __construct(
        private readonly FhirSource $source,
        private readonly SyncStateStore $state,
        private readonly string $sourceId,
    ) {
    }

    /** @param list<string> $types */
    public function pull(array $types): SyncPlan
    {
        $plan = new SyncPlan();
        $since = $this->state->lastPulledAt($this->sourceId);

        foreach ($types as $type) {
            foreach ($this->source->fetch($type, $since) as $resource) {
                $plan->add($this->classify($type, $resource));
            }
        }

        return $plan;
    }

    /** @param array<string, mixed> $resource */
    private function classify(string $type, array $resource): SyncItem
    {
        $id = $resource['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return new SyncItem($type, null, SyncItem::UNRESOLVED, $resource, unresolvedReason: 'resource has no id');
        }

        $sourceKey = "{$type}/{$id}";
        $fingerprint = self::fingerprint($resource);
        $prior = $this->state->get($this->sourceId, $sourceKey);

        if ($prior === null) {
            return new SyncItem($type, $sourceKey, SyncItem::NEW, $resource);
        }

        if ($prior['contentFingerprint'] !== $fingerprint) {
            return new SyncItem($type, $sourceKey, SyncItem::CHANGED, $resource, priorVaultEntryId: $prior['vaultEntryId']);
        }

        return new SyncItem($type, $sourceKey, SyncItem::UNCHANGED, $resource);
    }

    /** @return list<Candidate> */
    public function toCandidates(SyncPlan $plan): array
    {
        $candidates = [];
        foreach ([...$plan->itemsOf(SyncItem::NEW), ...$plan->itemsOf(SyncItem::CHANGED)] as $item) {
            $domain = self::RESOURCE_DOMAINS[$item->resourceType] ?? null;
            if ($domain === null) {
                continue; // not a clinical domain (e.g. Patient) — never turned into a candidate
            }

            $payload = $item->resource;
            $versionId = $payload['meta']['versionId'] ?? null;
            unset($payload['id'], $payload['meta']);

            $candidates[] = new Candidate(
                domain: $domain,
                resourceType: $item->resourceType,
                payload: $payload,
                codingSource: Candidate::CODING_SOURCE,
                route: Candidate::ROUTE_DETERMINISTIC,
                provenance: [
                    'source_system' => $this->sourceId,
                    'source_key' => $item->sourceKey,
                    'source_version_id' => is_string($versionId) ? $versionId : null,
                    'extraction_method' => 'fhir-sync',
                    // Fingerprint of the RAW resource as classified — carried here so
                    // callers (e.g. the sync CLI) can call recordCommitted() without
                    // recomputing it from the stripped payload, which would not match.
                    'content_fingerprint' => self::fingerprint($item->resource),
                ],
                sensitiveCategory: SensitiveClassifier::classify($domain, $item->resource),
                replacesVaultEntryId: $item->priorVaultEntryId,
            );
        }

        return $candidates;
    }

    public function recordCommitted(string $sourceKey, string $versionId, string $vaultEntryId, string $fingerprint): void
    {
        $this->state->put($this->sourceId, $sourceKey, $versionId, $vaultEntryId, $fingerprint);
    }

    public function markPulled(string $pulledAt): void
    {
        $this->state->markPulled($this->sourceId, $pulledAt);
    }

    /**
     * sha256 of the canonicalized (recursively key-sorted) resource JSON,
     * excluding `meta` — so a meta.lastUpdated touch with no content change is
     * NOT reported as a change.
     *
     * @param array<string, mixed> $resource
     */
    public static function fingerprint(array $resource): string
    {
        unset($resource['meta']);

        return hash('sha256', json_encode(self::canonicalize($resource), JSON_THROW_ON_ERROR));
    }

    /** @param mixed $value */
    private static function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
