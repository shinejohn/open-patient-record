<?php

declare(strict_types=1);

namespace Opr\Gateway;

/**
 * Deterministic terminology handling (build plan §G0.3). In G0 we do NOT guess
 * codes — we VALIDATE codes already present in the source against known systems,
 * do a small set of deterministic crosswalks, and otherwise leave the entry
 * uncoded text. A wrong code is worse than no code; force-coding is forbidden.
 *
 * The reference implementation ships a tiny built-in table; production loads full
 * NLM/CDC releases via importers. The behavior — validate, never guess — is the
 * point, not the table size.
 */
final class Terminology
{
    public const SYSTEM_RXNORM = 'http://www.nlm.nih.gov/research/umls/rxnorm';
    public const SYSTEM_SNOMED = 'http://snomed.info/sct';
    public const SYSTEM_LOINC = 'http://loinc.org';
    public const SYSTEM_ICD10 = 'http://hl7.org/fhir/sid/icd-10-cm';
    public const SYSTEM_CVX = 'http://hl7.org/fhir/sid/cvx';
    public const SYSTEM_NDC = 'http://hl7.org/fhir/sid/ndc';

    /** OIDs seen in C-CDA map to URIs. */
    private const OID_TO_SYSTEM = [
        '2.16.840.1.113883.6.88' => self::SYSTEM_RXNORM,
        '2.16.840.1.113883.6.96' => self::SYSTEM_SNOMED,
        '2.16.840.1.113883.6.1' => self::SYSTEM_LOINC,
        '2.16.840.1.113883.6.90' => self::SYSTEM_ICD10,
        '2.16.840.1.113883.12.292' => self::SYSTEM_CVX,
        '2.16.840.1.113883.6.69' => self::SYSTEM_NDC,
    ];

    public static function systemForOid(?string $oid): ?string
    {
        return $oid === null ? null : (self::OID_TO_SYSTEM[$oid] ?? null);
    }

    /**
     * Validate that a code looks well-formed for its system (shape validation —
     * the reference impl does not carry the full code sets). Returns the coding
     * source classification the caller records on the candidate.
     */
    public static function classifyCoding(?string $system, ?string $code): string
    {
        if ($system === null || $code === null || trim($code) === '') {
            return Candidate::CODING_NONE;
        }

        $valid = match ($system) {
            self::SYSTEM_RXNORM, self::SYSTEM_CVX => preg_match('/\A\d{1,7}\z/', $code) === 1,
            self::SYSTEM_ICD10 => preg_match('/\A[A-TV-Z]\d[0-9A-Z](\.[0-9A-Z]{1,4})?\z/', $code) === 1,
            self::SYSTEM_SNOMED => preg_match('/\A\d{6,18}\z/', $code) === 1,
            self::SYSTEM_LOINC => preg_match('/\A\d{1,5}-\d\z/', $code) === 1,
            self::SYSTEM_NDC => preg_match('/\A\d{4,5}-\d{3,4}-\d{1,2}\z/', $code) === 1,
            default => false,
        };

        return $valid ? Candidate::CODING_SOURCE : Candidate::CODING_NONE;
    }

    /** A FHIR CodeableConcept from validated coding, or a text-only concept. */
    public static function codeableConcept(?string $system, ?string $code, ?string $text): array
    {
        $concept = [];
        if (self::classifyCoding($system, $code) === Candidate::CODING_SOURCE) {
            $concept['coding'] = [['system' => $system, 'code' => $code] + ($text !== null ? ['display' => $text] : [])];
        }
        if ($text !== null && trim($text) !== '') {
            $concept['text'] = $text;
        }

        return $concept;
    }
}
