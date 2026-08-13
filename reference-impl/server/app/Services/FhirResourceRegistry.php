<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The FHIR resource types this server supports on its strict surface (the FHIR
 * door), with the R4 elements whose cardinality is 1..* — the ones a resource is
 * not itself without.
 *
 * Scope note. This is deliberately NOT a StructureDefinition engine. It enforces
 * required-element presence and choice-element presence ("medication[x]": one of
 * the listed keys must exist), nothing deeper. Full profile validation (US Core,
 * value-set bindings, slicing) is later F1 work and will layer on top; this
 * registry is what makes "you cannot commit {\"foo\":\"bar\"} as a Condition
 * through the FHIR door" true today.
 *
 * The native OPR envelope (POST /vaults/{vault}/entries) intentionally does NOT
 * consult this registry: custody is format-neutral, and the Gateway commits
 * artifacts whose types evolve ahead of this list. Strictness belongs to the
 * FHIR surface, where a stock client has no envelope to carry context in.
 */
final class FhirResourceRegistry
{
    /**
     * type => [required elements, choice groups (one-of)].
     *
     * @var array<string, array{required: list<string>, choices: list<list<string>>}>
     */
    private const REGISTRY = [
        'Patient' => ['required' => [], 'choices' => []],
        'Condition' => ['required' => ['subject'], 'choices' => []],
        'Observation' => ['required' => ['status', 'code'], 'choices' => []],
        'MedicationStatement' => [
            'required' => ['status', 'subject'],
            'choices' => [['medicationCodeableConcept', 'medicationReference']],
        ],
        'MedicationRequest' => [
            'required' => ['status', 'intent', 'subject'],
            'choices' => [['medicationCodeableConcept', 'medicationReference']],
        ],
        'AllergyIntolerance' => ['required' => ['patient'], 'choices' => []],
        'Immunization' => [
            'required' => ['status', 'vaccineCode', 'patient'],
            'choices' => [['occurrenceDateTime', 'occurrenceString']],
        ],
        'DiagnosticReport' => ['required' => ['status', 'code'], 'choices' => []],
        'Procedure' => ['required' => ['status', 'subject'], 'choices' => []],
        'Encounter' => ['required' => ['status', 'class'], 'choices' => []],
        'DocumentReference' => ['required' => ['status', 'content'], 'choices' => []],
        'CarePlan' => ['required' => ['status', 'intent', 'subject'], 'choices' => []],
        'Medication' => ['required' => [], 'choices' => []],
        'Coverage' => ['required' => ['status', 'beneficiary', 'payor'], 'choices' => []],
        'ServiceRequest' => ['required' => ['status', 'intent', 'subject'], 'choices' => []],
    ];

    /**
     * Search parameters per type: param => [type: token|reference|date, paths].
     * Paths are dot-paths into the payload; a param may probe several (choice
     * elements). Universal params (_id, _lastUpdated, _count, _offset) are
     * handled by the engine directly.
     *
     * @var array<string, array<string, array{type: string, paths: list<string>}>>
     */
    private const SEARCH = [
        'Condition' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'category' => ['type' => 'token', 'paths' => ['category.0']],
            'clinical-status' => ['type' => 'token', 'paths' => ['clinicalStatus']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'onset-date' => ['type' => 'date', 'paths' => ['onsetDateTime', 'recordedDate']],
        ],
        'Observation' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'category' => ['type' => 'token', 'paths' => ['category.0']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['effectiveDateTime', 'effectivePeriod.start']],
        ],
        'MedicationStatement' => [
            'code' => ['type' => 'token', 'paths' => ['medicationCodeableConcept']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'effective' => ['type' => 'date', 'paths' => ['effectiveDateTime', 'effectivePeriod.start']],
        ],
        'MedicationRequest' => [
            'code' => ['type' => 'token', 'paths' => ['medicationCodeableConcept']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'intent' => ['type' => 'token', 'paths' => ['intent']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'authoredon' => ['type' => 'date', 'paths' => ['authoredOn']],
        ],
        'AllergyIntolerance' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'clinical-status' => ['type' => 'token', 'paths' => ['clinicalStatus']],
            'patient' => ['type' => 'reference', 'paths' => ['patient']],
            'date' => ['type' => 'date', 'paths' => ['recordedDate']],
        ],
        'Immunization' => [
            'vaccine-code' => ['type' => 'token', 'paths' => ['vaccineCode']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['patient']],
            'date' => ['type' => 'date', 'paths' => ['occurrenceDateTime']],
        ],
        'DiagnosticReport' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'category' => ['type' => 'token', 'paths' => ['category.0']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['effectiveDateTime', 'effectivePeriod.start']],
        ],
        'Procedure' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['performedDateTime', 'performedPeriod.start']],
        ],
        'Encounter' => [
            'status' => ['type' => 'token', 'paths' => ['status']],
            'class' => ['type' => 'token', 'paths' => ['class']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['period.start']],
        ],
        'DocumentReference' => [
            'status' => ['type' => 'token', 'paths' => ['status']],
            'category' => ['type' => 'token', 'paths' => ['category.0']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['date']],
        ],
        'CarePlan' => [
            'status' => ['type' => 'token', 'paths' => ['status']],
            'category' => ['type' => 'token', 'paths' => ['category.0']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'date' => ['type' => 'date', 'paths' => ['period.start']],
        ],
        'Medication' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'status' => ['type' => 'token', 'paths' => ['status']],
        ],
        'Coverage' => [
            'status' => ['type' => 'token', 'paths' => ['status']],
            'patient' => ['type' => 'reference', 'paths' => ['beneficiary']],
            'payor' => ['type' => 'reference', 'paths' => ['payor.0']],
        ],
        'ServiceRequest' => [
            'code' => ['type' => 'token', 'paths' => ['code']],
            'status' => ['type' => 'token', 'paths' => ['status']],
            'intent' => ['type' => 'token', 'paths' => ['intent']],
            'patient' => ['type' => 'reference', 'paths' => ['subject']],
            'authored' => ['type' => 'date', 'paths' => ['authoredOn']],
        ],
        'Patient' => [],
    ];

    public static function isSupported(string $type): bool
    {
        return array_key_exists($type, self::REGISTRY);
    }

    /**
     * US Core profile stamping — PRESENCE-level only, on purpose. A profile URL
     * is claimed when (a) the type's base required elements validate and (b) the
     * profile's additional must-have elements are present. This makes "this
     * resource is US-Core-shaped" an earned claim without pretending to be a
     * binding/slicing validator; a resource that doesn't qualify simply carries
     * no profile — an honest absence, never a false badge. Observation is
     * special-cased: the lab profile applies only to laboratory-category
     * observations.
     *
     * @var array<string, array{url: string, extras: list<string>}>
     */
    private const US_CORE = [
        'Patient' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-patient', 'extras' => ['name', 'gender']],
        'Condition' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-condition', 'extras' => ['code', 'category']],
        'AllergyIntolerance' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-allergyintolerance', 'extras' => ['code']],
        'Immunization' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-immunization', 'extras' => []],
        'MedicationRequest' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-medicationrequest', 'extras' => ['requester']],
        'Procedure' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-procedure', 'extras' => ['code']],
        'Encounter' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-encounter', 'extras' => ['type']],
        'DiagnosticReport' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-diagnosticreport-lab', 'extras' => ['category']],
        'DocumentReference' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-documentreference', 'extras' => ['type']],
        'CarePlan' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-careplan', 'extras' => ['category']],
        'Medication' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-medication', 'extras' => ['code']],
        'Coverage' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-coverage', 'extras' => []],
        'ServiceRequest' => ['url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-servicerequest', 'extras' => ['code']],
    ];

    /**
     * The US Core profile URL this payload qualifies for, or null.
     *
     * @param array<string, mixed> $payload
     */
    public static function usCoreProfile(string $type, array $payload): ?string
    {
        if (self::missingElements($type, $payload) !== []) {
            return null; // base shape not even satisfied — no claim
        }

        if ($type === 'Observation') {
            foreach (($payload['category'] ?? []) as $category) {
                foreach (($category['coding'] ?? []) as $coding) {
                    if (($coding['code'] ?? null) === 'laboratory') {
                        return 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-lab';
                    }
                }
            }

            return null;
        }

        $def = self::US_CORE[$type] ?? null;
        if ($def === null) {
            return null;
        }

        foreach ($def['extras'] as $element) {
            if (! array_key_exists($element, $payload) || $payload[$element] === null || $payload[$element] === []) {
                return null;
            }
        }

        return $def['url'];
    }

    /** @return array{type: string, paths: list<string>}|null */
    public static function searchParam(string $type, string $param): ?array
    {
        return self::SEARCH[$type][$param] ?? null;
    }

    /** @return array<string, array{type: string, paths: list<string>}> */
    public static function searchParams(string $type): array
    {
        return self::SEARCH[$type] ?? [];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * Missing required-element paths for a payload, FHIRPath-style
     * ("Observation.status"). Empty list = structurally acceptable.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function missingElements(string $type, array $payload): array
    {
        $def = self::REGISTRY[$type] ?? null;
        if ($def === null) {
            return []; // unsupported types are rejected before validation
        }

        $missing = [];
        foreach ($def['required'] as $element) {
            if (! array_key_exists($element, $payload) || $payload[$element] === null || $payload[$element] === []) {
                $missing[] = "{$type}.{$element}";
            }
        }
        foreach ($def['choices'] as $group) {
            $satisfied = false;
            foreach ($group as $option) {
                if (array_key_exists($option, $payload) && $payload[$option] !== null && $payload[$option] !== []) {
                    $satisfied = true;
                    break;
                }
            }
            if (! $satisfied) {
                // Report the choice in [x] form: medicationCodeableConcept|medicationReference
                // shares the prefix up to the first capitalized suffix — name the group.
                $missing[] = "{$type}.".implode('|', $group);
            }
        }

        return $missing;
    }
}
