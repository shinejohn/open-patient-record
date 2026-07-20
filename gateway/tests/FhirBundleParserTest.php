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
        // 5 clinical resources; the Patient is NOT a candidate.
        $this->assertCount(5, $result->candidates);
        $domains = array_map(fn (Candidate $c) => $c->domain, $result->candidates);
        $this->assertEqualsCanonicalizing(
            [Candidate::DOMAIN_PROBLEM, Candidate::DOMAIN_MEDICATION, Candidate::DOMAIN_ALLERGY, Candidate::DOMAIN_IMMUNIZATION, Candidate::DOMAIN_MEDICATION],
            $domains,
        );
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
