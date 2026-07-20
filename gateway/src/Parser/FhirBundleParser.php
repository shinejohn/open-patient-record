<?php

declare(strict_types=1);

namespace Opr\Gateway\Parser;

use Opr\Gateway\Candidate;
use Opr\Gateway\IngestionResult;

/**
 * Parses a FHIR R4 Bundle (e.g. an Apple Health Records export). This is the
 * highest-fidelity, ZERO-extraction-risk path: the content is already FHIR, so
 * we map resources to candidates with coding-source "source-coded" and route
 * "deterministic". No AI, no guessing (build plan §G0.2).
 */
final class FhirBundleParser implements Parser
{
    private const RESOURCE_DOMAINS = [
        'MedicationStatement' => Candidate::DOMAIN_MEDICATION,
        'MedicationRequest' => Candidate::DOMAIN_MEDICATION,
        'AllergyIntolerance' => Candidate::DOMAIN_ALLERGY,
        'Condition' => Candidate::DOMAIN_PROBLEM,
        'Immunization' => Candidate::DOMAIN_IMMUNIZATION,
        'Observation' => Candidate::DOMAIN_RESULT,
        'DiagnosticReport' => Candidate::DOMAIN_RESULT,
    ];

    public function supports(string $content): bool
    {
        $trimmed = ltrim($content);
        if (! str_starts_with($trimmed, '{')) {
            return false;
        }
        $data = json_decode($trimmed, true);

        return is_array($data) && ($data['resourceType'] ?? null) === 'Bundle';
    }

    public function classification(): string
    {
        return 'fhir-bundle';
    }

    public function parse(string $content): IngestionResult
    {
        $result = new IngestionResult();
        $result->classification = $this->classification();
        $result->sourceSha256 = hash('sha256', $content);

        $bundle = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        foreach ($bundle['entry'] ?? [] as $index => $entry) {
            $resource = $entry['resource'] ?? null;
            if (! is_array($resource)) {
                continue;
            }
            $type = $resource['resourceType'] ?? null;
            $domain = self::RESOURCE_DOMAINS[$type] ?? null;
            if ($domain === null) {
                continue; // Patient, Practitioner, etc. — not clinical candidates
            }

            // Strip server-assigned identity/metadata; the vault re-establishes it.
            unset($resource['id'], $resource['meta']);

            $result->add(new Candidate(
                domain: $domain,
                resourceType: $type,
                payload: $resource,
                codingSource: Candidate::CODING_SOURCE,
                route: Candidate::ROUTE_DETERMINISTIC,
                provenance: [
                    'organization' => $this->sourceName($bundle),
                    'source_system' => 'fhir-bundle-import',
                    'extraction_method' => 'structured-parse',
                    'source_span' => "Bundle.entry[{$index}]",
                ],
                sensitiveCategory: SensitiveClassifier::classify($domain, $resource),
            ));
        }

        return $result;
    }

    private function sourceName(array $bundle): string
    {
        // Apple Health puts a source in meta or in a Device/Organization resource;
        // fall back to a generic label rather than inventing specificity.
        return $bundle['meta']['source'] ?? 'Patient-provided FHIR export';
    }
}
