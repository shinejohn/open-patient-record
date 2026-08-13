<?php

declare(strict_types=1);

namespace Opr\Gateway\Tests;

use Opr\Gateway\Candidate;
use Opr\Gateway\Gateway;
use PHPUnit\Framework\TestCase;

final class CcdaParserTest extends TestCase
{
    private function ingestFixture(): \Opr\Gateway\IngestionResult
    {
        return (new Gateway())->ingestFile(__DIR__.'/../fixtures/ccd-sample.xml');
    }

    public function test_parses_all_structured_sections(): void
    {
        $result = $this->ingestFixture();
        $this->assertSame('ccda', $result->classification);

        $byDomain = [];
        foreach ($result->candidates as $c) {
            $byDomain[$c->domain] = ($byDomain[$c->domain] ?? 0) + 1;
        }

        $this->assertSame(2, $byDomain[Candidate::DOMAIN_MEDICATION]);    // Lisinopril, Metformin
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_ALLERGY]);
        $this->assertSame(2, $byDomain[Candidate::DOMAIN_PROBLEM]);
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_IMMUNIZATION]);
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_RESULT]);
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_PROCEDURE]);     // Appendectomy
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_ENCOUNTER]);     // Office visit
        $this->assertSame(1, $byDomain[Candidate::DOMAIN_VITAL]);         // Systolic BP
        // Document domain: the document itself + one identifiable externalDocument.
        $this->assertSame(2, $byDomain[Candidate::DOMAIN_DOCUMENT]);
    }

    public function test_procedure_is_extracted_with_validated_snomed_code(): void
    {
        $result = $this->ingestFixture();
        $appendectomy = $this->find($result, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_PROCEDURE);

        $this->assertNotNull($appendectomy);
        $this->assertSame('Procedure', $appendectomy->resourceType);
        $this->assertSame(Candidate::CODING_SOURCE, $appendectomy->codingSource);
        $this->assertSame('80146002', $appendectomy->payload['code']['coding'][0]['code']);
    }

    public function test_narrative_only_procedure_is_counted_unresolved_not_fabricated(): void
    {
        $result = $this->ingestFixture();

        $this->assertSame(1, $result->mentionCounts[Candidate::DOMAIN_PROCEDURE]['unresolved']);
        $procedures = array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_PROCEDURE);
        $this->assertCount(1, $procedures);
    }

    public function test_encounter_is_extracted(): void
    {
        $result = $this->ingestFixture();
        $encounter = $this->find($result, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_ENCOUNTER);

        $this->assertNotNull($encounter);
        $this->assertSame('Encounter', $encounter->resourceType);
        $this->assertSame('185349003', $encounter->payload['type'][0]['coding'][0]['code']);
        $this->assertSame('2025-01-15', $encounter->payload['period']['start']);
    }

    public function test_vital_sign_is_extracted_as_distinct_domain_from_results(): void
    {
        $result = $this->ingestFixture();
        $vital = $this->find($result, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_VITAL);

        $this->assertNotNull($vital);
        $this->assertSame('Observation', $vital->resourceType);
        $this->assertSame('vital-signs', $vital->payload['category'][0]['coding'][0]['code']);
        $this->assertSame(120.0, $vital->payload['valueQuantity']['value']);
        // The unresolvable observation in the same organizer is a mention, not a fabrication.
        $this->assertSame(1, $result->mentionCounts[Candidate::DOMAIN_VITAL]['unresolved']);
    }

    public function test_document_self_reference_and_external_document_are_extracted(): void
    {
        $result = $this->ingestFixture();
        $documents = array_values(array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_DOCUMENT));

        $this->assertCount(2, $documents);
        $selfDoc = $this->find($result, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_DOCUMENT && ($c->payload['type']['text'] ?? null) === 'Continuity of Care Document');
        $externalDoc = $this->find($result, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_DOCUMENT && ($c->payload['type']['text'] ?? null) === 'Diagnostic Imaging Report');

        $this->assertNotNull($selfDoc);
        $this->assertNotNull($externalDoc);
        $this->assertSame('https://example-imaging.org/reports/98765', $externalDoc->payload['content'][0]['attachment']['url']);
    }

    public function test_external_document_with_no_identifying_info_is_unresolved_not_fabricated(): void
    {
        $result = $this->ingestFixture();

        $this->assertSame(1, $result->mentionCounts[Candidate::DOMAIN_DOCUMENT]['unresolved']);
    }

    public function test_validated_source_codes_are_preserved_with_system_uris(): void
    {
        $result = $this->ingestFixture();
        $lisinopril = $this->find($result, fn (Candidate $c) => ($c->payload['medicationCodeableConcept']['text'] ?? '') === 'Lisinopril 10 MG Oral Tablet');

        $this->assertNotNull($lisinopril);
        $this->assertSame(Candidate::CODING_SOURCE, $lisinopril->codingSource);
        $coding = $lisinopril->payload['medicationCodeableConcept']['coding'][0];
        $this->assertSame('http://www.nlm.nih.gov/research/umls/rxnorm', $coding['system']);
        $this->assertSame('314076', $coding['code']);
    }

    public function test_narrative_only_medication_is_counted_unresolved_not_fabricated(): void
    {
        $result = $this->ingestFixture();

        // The nullFlavor 'Herbal supplement' entry has no code and no code text:
        // counted as an unresolved mention, never invented into a coded fact.
        $this->assertSame(1, $result->mentionCounts[Candidate::DOMAIN_MEDICATION]['unresolved']);
        $this->assertGreaterThanOrEqual(1, $result->unresolvedCount());

        // No candidate was fabricated for it.
        $meds = array_filter($result->candidates, fn (Candidate $c) => $c->domain === Candidate::DOMAIN_MEDICATION);
        $this->assertCount(2, $meds);
    }

    public function test_sensitive_problem_is_flagged_from_narrative_text(): void
    {
        $result = $this->ingestFixture();
        $sud = $this->find($result, fn (Candidate $c) => $c->sensitiveCategory === '42_cfr_part_2');

        $this->assertNotNull($sud);
        $this->assertSame(Candidate::DOMAIN_PROBLEM, $sud->domain);
    }

    public function test_provenance_carries_organization_and_span(): void
    {
        $result = $this->ingestFixture();
        $first = $result->candidates[0];

        $this->assertSame('Riverside Family Medicine', $first->provenance['organization']);
        $this->assertArrayHasKey('source_span', $first->provenance);
        $this->assertSame('ccda-import', $first->provenance['source_system']);
    }

    private function find(\Opr\Gateway\IngestionResult $r, callable $pred): ?Candidate
    {
        foreach ($r->candidates as $c) {
            if ($pred($c)) {
                return $c;
            }
        }

        return null;
    }
}
