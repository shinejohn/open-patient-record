<?php

declare(strict_types=1);

namespace Opr\Gateway\Tests;

use Opr\Gateway\Candidate;
use Opr\Gateway\Gateway;
use PHPUnit\Framework\TestCase;

final class FhirBundleParserTest extends TestCase
{
    private function ingestFixture(): \Opr\Gateway\IngestionResult
    {
        return (new Gateway())->ingestFile(__DIR__.'/../fixtures/apple-health-export.json');
    }

    public function test_classifies_and_extracts_clinical_resources_only(): void
    {
        $result = $this->ingestFixture();

        $this->assertSame('fhir-bundle', $result->classification);
        // 10 clinical resources; the Patient is NOT a candidate.
        $this->assertCount(10, $result->candidates);
        $domains = array_map(fn (Candidate $c) => $c->domain, $result->candidates);
        $this->assertEqualsCanonicalizing(
            [
                Candidate::DOMAIN_PROBLEM,
                Candidate::DOMAIN_MEDICATION,
                Candidate::DOMAIN_ALLERGY,
                Candidate::DOMAIN_IMMUNIZATION,
                Candidate::DOMAIN_MEDICATION,
                Candidate::DOMAIN_PROCEDURE,
                Candidate::DOMAIN_ENCOUNTER,
                Candidate::DOMAIN_VITAL,
                Candidate::DOMAIN_RESULT,
                Candidate::DOMAIN_DOCUMENT,
            ],
            $domains,
        );
    }

    public function test_vital_signs_observation_is_kept_distinct_from_lab_result(): void
    {
        $result = $this->ingestFixture();

        $vitals = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_VITAL));
        $labs = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_RESULT));

        $this->assertCount(1, $vitals);
        $this->assertSame('Systolic BP', $vitals[0]->payload['code']['text']);
        $this->assertCount(1, $labs);
        $this->assertSame('HbA1c', $labs[0]->payload['code']['text']);
    }

    public function test_procedure_encounter_and_document_are_extracted(): void
    {
        $result = $this->ingestFixture();

        $procedure = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_PROCEDURE))[0];
        $encounter = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_ENCOUNTER))[0];
        $document = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_DOCUMENT))[0];

        $this->assertSame('Procedure', $procedure->resourceType);
        $this->assertSame('Encounter', $encounter->resourceType);
        $this->assertSame('DocumentReference', $document->resourceType);
    }

    public function test_all_fhir_candidates_are_deterministic_and_source_coded(): void
    {
        foreach ($this->ingestFixture()->candidates as $c) {
            $this->assertSame(Candidate::ROUTE_DETERMINISTIC, $c->route);
            $this->assertSame(Candidate::CODING_SOURCE, $c->codingSource);
            $this->assertSame('structured-parse', $c->provenance['extraction_method']);
            $this->assertArrayNotHasKey('id', $c->payload); // server identity stripped
        }
    }

    public function test_sensitive_content_is_flagged(): void
    {
        $result = $this->ingestFixture();
        $sensitive = array_filter($result->candidates, fn (Candidate $c) => $c->sensitiveCategory !== null);

        $this->assertCount(1, $sensitive);
        $this->assertSame('42_cfr_part_2', array_values($sensitive)[0]->sensitiveCategory);
    }

    public function test_completeness_accounting_is_present(): void
    {
        $result = $this->ingestFixture();

        $this->assertSame(2, $result->mentionCounts[Candidate::DOMAIN_MEDICATION]['extracted']);
        $this->assertSame(0, $result->unresolvedCount()); // clean FHIR: nothing unresolved
    }
}
