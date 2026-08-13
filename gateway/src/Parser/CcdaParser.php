<?php

declare(strict_types=1);

namespace Opr\Gateway\Parser;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Opr\Gateway\Candidate;
use Opr\Gateway\IngestionResult;
use Opr\Gateway\Terminology;

/**
 * Parses the STRUCTURED entries of a C-CDA (Consolidated CDA) document — the
 * workhorse legacy format (build plan §G0.2). We parse coded entries
 * deterministically; narrative-only content is COUNTED as an unresolved mention
 * (a visible work item) but never fabricated into a coded fact. AI extraction of
 * narrative is G1, gated by a BAA.
 *
 * Section LOINC codes drive dispatch:
 *   10160-0 medications · 48765-2 allergies · 11450-4 problems
 *   11369-6 immunizations · 30954-2 results · 47519-4 procedures
 *   46240-8 encounters · 8716-3 vital signs
 *
 * DOCUMENTS have no C-CDA section of their own: the document itself becomes
 * one DocumentReference candidate, and any <externalDocument> the document
 * points to (e.g. a referenced scan or prior note) becomes another.
 */
final class CcdaParser implements Parser
{
    private const HL7 = 'urn:hl7-org:v3';

    private const SECTIONS = [
        '10160-0' => 'parseMedications',
        '48765-2' => 'parseAllergies',
        '11450-4' => 'parseProblems',
        '11369-6' => 'parseImmunizations',
        '30954-2' => 'parseResults',
        '47519-4' => 'parseProcedures',
        '46240-8' => 'parseEncounters',
        '8716-3' => 'parseVitals',
    ];

    public function supports(string $content): bool
    {
        $trimmed = ltrim($content);
        if (! str_starts_with($trimmed, '<')) {
            return false;
        }

        return str_contains($content, 'urn:hl7-org:v3') && str_contains($content, 'ClinicalDocument');
    }

    public function classification(): string
    {
        return 'ccda';
    }

    public function parse(string $content): IngestionResult
    {
        $result = new IngestionResult();
        $result->classification = $this->classification();
        $result->sourceSha256 = hash('sha256', $content);

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadXML($content, LIBXML_NONET | LIBXML_NOENT);
        libxml_use_internal_errors($prev);

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('h', self::HL7);

        $org = $this->documentOrganization($xp);

        foreach ($xp->query('//h:section') as $section) {
            $sectionCode = $this->attr($xp, 'h:code/@code', $section);
            $handler = self::SECTIONS[$sectionCode] ?? null;
            if ($handler === null) {
                continue;
            }

            foreach ($xp->query('.//h:entry', $section) as $i => $entry) {
                $this->{$handler}($xp, $entry, $result, $org, $i);
            }
        }

        $this->parseDocuments($xp, $doc, $result, $org);

        return $result;
    }

    private function parseMedications(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:manufacturedMaterial/h:code', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $this->text($xp, './/h:manufacturedMaterial/h:code', $entry)
            ?? $this->originalTextRef($xp, $node);

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_MEDICATION); // narrative-only med
            return;
        }

        $payload = [
            'resourceType' => 'MedicationStatement',
            'status' => 'unknown',
            'medicationCodeableConcept' => Terminology::codeableConcept($system, $code, $text),
        ];
        if (($eff = $this->text($xp, './/h:effectiveTime/h:low/@value', $entry)) !== null) {
            $payload['effectiveDateTime'] = $this->fhirDate($eff);
        }

        $this->emit($r, Candidate::DOMAIN_MEDICATION, 'MedicationStatement', $payload, $system, $code, $org, "medication[{$i}]", $text);
    }

    private function parseAllergies(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:participant//h:code', $entry)->item(0)
            ?? $xp->query('.//h:observation/h:value', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $node instanceof DOMElement ? ($node->getAttribute('displayName') ?: $this->originalTextRef($xp, $node)) : null;

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_ALLERGY);
            return;
        }

        $payload = [
            'resourceType' => 'AllergyIntolerance',
            'clinicalStatus' => ['coding' => [['code' => 'active']]],
            'code' => Terminology::codeableConcept($system, $code, $text),
        ];
        if (($reaction = $this->text($xp, './/h:entryRelationship//h:value/@displayName', $entry)) !== null) {
            $payload['reaction'] = [['manifestation' => [['text' => $reaction]]]];
        }

        $this->emit($r, Candidate::DOMAIN_ALLERGY, 'AllergyIntolerance', $payload, $system, $code, $org, "allergy[{$i}]", $text);
    }

    private function parseProblems(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:observation/h:value', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $node instanceof DOMElement ? ($node->getAttribute('displayName') ?: $this->originalTextRef($xp, $node)) : null;

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_PROBLEM);
            return;
        }

        $payload = [
            'resourceType' => 'Condition',
            'clinicalStatus' => ['coding' => [['code' => 'active']]],
            'code' => Terminology::codeableConcept($system, $code, $text),
        ];
        if (($onset = $this->text($xp, './/h:effectiveTime/h:low/@value', $entry)) !== null) {
            $payload['onsetDateTime'] = $this->fhirDate($onset);
        }

        $this->emit($r, Candidate::DOMAIN_PROBLEM, 'Condition', $payload, $system, $code, $org, "problem[{$i}]", $text);
    }

    private function parseImmunizations(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:manufacturedMaterial/h:code', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $this->text($xp, './/h:manufacturedMaterial/h:code/@displayName', $entry);

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_IMMUNIZATION);
            return;
        }

        $payload = [
            'resourceType' => 'Immunization',
            'status' => 'completed',
            'vaccineCode' => Terminology::codeableConcept($system, $code, $text),
        ];
        if (($occ = $this->text($xp, './/h:effectiveTime/@value', $entry)) !== null) {
            $payload['occurrenceDateTime'] = $this->fhirDate($occ);
        }

        $this->emit($r, Candidate::DOMAIN_IMMUNIZATION, 'Immunization', $payload, $system, $code, $org, "immunization[{$i}]", $text);
    }

    private function parseResults(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        foreach ($xp->query('.//h:observation', $entry) as $j => $obs) {
            $node = $xp->query('./h:code', $obs)->item(0);
            [$system, $code] = $this->codeAndSystem($xp, $node);
            $text = $node instanceof DOMElement ? $node->getAttribute('displayName') : null;

            if ($text === null && $code === null) {
                $r->noteUnextractedMention(Candidate::DOMAIN_RESULT);
                continue;
            }

            $payload = [
                'resourceType' => 'Observation',
                'status' => 'final',
                'code' => Terminology::codeableConcept($system, $code, $text),
            ];
            $valueNode = $xp->query('./h:value', $obs)->item(0);
            if ($valueNode instanceof DOMElement) {
                $val = $valueNode->getAttribute('value');
                $unit = $valueNode->getAttribute('unit');
                if ($val !== '') {
                    $payload['valueQuantity'] = ['value' => (float) $val] + ($unit !== '' ? ['unit' => $unit] : []);
                }
            }

            $this->emit($r, Candidate::DOMAIN_RESULT, 'Observation', $payload, $system, $code, $org, "result[{$i}.{$j}]", $text);
        }
    }

    private function parseProcedures(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:procedure/h:code', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $node instanceof DOMElement ? ($node->getAttribute('displayName') ?: $this->originalTextRef($xp, $node)) : null;

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_PROCEDURE); // narrative-only procedure
            return;
        }

        $payload = [
            'resourceType' => 'Procedure',
            'status' => 'completed',
            'code' => Terminology::codeableConcept($system, $code, $text),
        ];
        if (($performed = $this->text($xp, './/h:procedure/h:effectiveTime/@value', $entry)) !== null) {
            $payload['performedDateTime'] = $this->fhirDate($performed);
        }

        $this->emit($r, Candidate::DOMAIN_PROCEDURE, 'Procedure', $payload, $system, $code, $org, "procedure[{$i}]", $text);
    }

    private function parseEncounters(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        $node = $xp->query('.//h:encounter/h:code', $entry)->item(0);
        [$system, $code] = $this->codeAndSystem($xp, $node);
        $text = $node instanceof DOMElement ? ($node->getAttribute('displayName') ?: $this->originalTextRef($xp, $node)) : null;

        if ($text === null && $code === null) {
            $r->noteUnextractedMention(Candidate::DOMAIN_ENCOUNTER); // narrative-only encounter
            return;
        }

        $payload = [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'type' => [Terminology::codeableConcept($system, $code, $text)],
        ];
        if (($start = $this->text($xp, './/h:encounter/h:effectiveTime/h:low/@value', $entry)) !== null) {
            $payload['period'] = ['start' => $this->fhirDate($start)];
        }

        $this->emit($r, Candidate::DOMAIN_ENCOUNTER, 'Encounter', $payload, $system, $code, $org, "encounter[{$i}]", $text);
    }

    private function parseVitals(DOMXPath $xp, DOMElement $entry, IngestionResult $r, string $org, int $i): void
    {
        foreach ($xp->query('.//h:observation', $entry) as $j => $obs) {
            $node = $xp->query('./h:code', $obs)->item(0);
            [$system, $code] = $this->codeAndSystem($xp, $node);
            $text = $node instanceof DOMElement ? ($node->getAttribute('displayName') ?: null) : null;

            if ($text === null && $code === null) {
                $r->noteUnextractedMention(Candidate::DOMAIN_VITAL);
                continue;
            }

            $payload = [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
                'code' => Terminology::codeableConcept($system, $code, $text),
            ];
            $valueNode = $xp->query('./h:value', $obs)->item(0);
            if ($valueNode instanceof DOMElement) {
                $val = $valueNode->getAttribute('value');
                $unit = $valueNode->getAttribute('unit');
                if ($val !== '') {
                    $payload['valueQuantity'] = ['value' => (float) $val] + ($unit !== '' ? ['unit' => $unit] : []);
                }
            }

            $this->emit($r, Candidate::DOMAIN_VITAL, 'Observation', $payload, $system, $code, $org, "vital[{$i}.{$j}]", $text);
        }
    }

    /**
     * DOCUMENTS have no C-CDA section: the document itself is one
     * DocumentReference candidate, and any <externalDocument> it references
     * (e.g. a prior note or imaging report held elsewhere) is another. A
     * referenced document with neither an id nor a code/title is an
     * unresolved mention — never fabricated into a coded fact.
     */
    private function parseDocuments(DOMXPath $xp, DOMDocument $doc, IngestionResult $r, string $org): void
    {
        $root = $doc->documentElement;
        if ($root instanceof DOMElement) {
            $codeNode = $xp->query('/h:ClinicalDocument/h:code')->item(0);
            [$system, $code] = $this->codeAndSystem($xp, $codeNode);
            $title = $this->text($xp, '/h:ClinicalDocument/h:title', null);
            $text = $title ?? ($codeNode instanceof DOMElement ? $codeNode->getAttribute('displayName') : null);

            $payload = [
                'resourceType' => 'DocumentReference',
                'status' => 'current',
                'type' => Terminology::codeableConcept($system, $code, $text),
            ];

            $this->emit($r, Candidate::DOMAIN_DOCUMENT, 'DocumentReference', $payload, $system, $code, $org, 'document[self]', $text);
        }

        foreach ($xp->query('//h:externalDocument') as $i => $extDoc) {
            $codeNode = $xp->query('./h:code', $extDoc)->item(0);
            [$system, $code] = $this->codeAndSystem($xp, $codeNode);
            $text = $codeNode instanceof DOMElement ? ($codeNode->getAttribute('displayName') ?: $this->originalTextRef($xp, $codeNode)) : null;

            if ($text === null && $code === null) {
                $r->noteUnextractedMention(Candidate::DOMAIN_DOCUMENT); // referenced document with no identifying info
                continue;
            }

            $payload = [
                'resourceType' => 'DocumentReference',
                'status' => 'current',
                'type' => Terminology::codeableConcept($system, $code, $text),
            ];
            if (($url = $this->text($xp, './h:text/h:reference/@value', $extDoc)) !== null) {
                $payload['content'] = [['attachment' => ['url' => $url]]];
            }

            $this->emit($r, Candidate::DOMAIN_DOCUMENT, 'DocumentReference', $payload, $system, $code, $org, "document[external.{$i}]", $text);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function emit(
        IngestionResult $r,
        string $domain,
        string $resourceType,
        array $payload,
        ?string $system,
        ?string $code,
        string $org,
        string $span,
        ?string $text,
    ): void {
        $codingSource = Terminology::classifyCoding($system, $code);

        $r->add(new Candidate(
            domain: $domain,
            resourceType: $resourceType,
            payload: $payload,
            codingSource: $codingSource,
            route: Candidate::ROUTE_DETERMINISTIC,
            provenance: [
                'organization' => $org,
                'source_system' => 'ccda-import',
                'extraction_method' => 'structured-parse',
                'source_span' => $span,
            ],
            sensitiveCategory: $text !== null ? SensitiveClassifier::classifyText($text) : null,
        ));
    }

    /** @return array{0: ?string, 1: ?string} [systemUri, code] */
    private function codeAndSystem(DOMXPath $xp, ?\DOMNode $node): array
    {
        if (! $node instanceof DOMElement) {
            return [null, null];
        }
        $code = $node->getAttribute('code') ?: null;
        $system = Terminology::systemForOid($node->getAttribute('codeSystem') ?: null);

        return [$system, $code];
    }

    private function documentOrganization(DOMXPath $xp): string
    {
        $name = $this->text($xp, '(//h:custodian//h:name | //h:author//h:representedOrganization/h:name)[1]', null);

        return $name ?? 'Imported C-CDA';
    }

    private function attr(DOMXPath $xp, string $q, DOMElement $ctx): ?string
    {
        $n = $xp->query($q, $ctx)->item(0);

        return $n?->nodeValue ?: null;
    }

    private function text(DOMXPath $xp, string $q, ?DOMElement $ctx): ?string
    {
        $n = $ctx === null ? $xp->query($q)->item(0) : $xp->query($q, $ctx)->item(0);
        $v = $n?->nodeValue;

        return ($v !== null && trim($v) !== '') ? trim($v) : null;
    }

    /** displayName preferred; else follow originalText <reference> into narrative. */
    private function originalTextRef(DOMXPath $xp, ?\DOMNode $node): ?string
    {
        if (! $node instanceof DOMElement) {
            return null;
        }
        $display = $node->getAttribute('displayName');

        return $display !== '' ? $display : null;
    }

    private function fhirDate(string $hl7): string
    {
        // HL7 date: YYYYMMDD[HHMMSS] → FHIR date/dateTime.
        $d = substr($hl7, 0, 8);
        if (strlen($d) === 8) {
            return substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
        }

        return substr($hl7, 0, 4);
    }
}
