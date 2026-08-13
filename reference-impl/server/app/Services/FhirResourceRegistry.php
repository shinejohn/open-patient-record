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

    /**
     * Required-binding value sets (R4 "required" strength only — the codes a
     * conformant resource MUST draw from) for the plain `code`-typed elements on
     * the profiles above. Scope note: this deliberately covers only scalar
     * `code` elements (status/intent/gender) with small, stable required
     * bindings copied from the base FHIR R4 spec. It does NOT bind
     * CodeableConcept-valued elements (e.g. Condition.clinicalStatus,
     * Encounter.class) — those require coding-system-aware slicing logic that
     * is out of scope here (see class doc comment). An honest subset, not a
     * terminology server.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const REQUIRED_BINDINGS = [
        'Observation' => ['status' => ['registered', 'preliminary', 'final', 'amended', 'corrected', 'cancelled', 'entered-in-error', 'unknown']],
        'MedicationStatement' => ['status' => ['active', 'completed', 'entered-in-error', 'intended', 'stopped', 'on-hold', 'unknown', 'not-taken']],
        'MedicationRequest' => [
            'status' => ['active', 'on-hold', 'cancelled', 'completed', 'entered-in-error', 'stopped', 'draft', 'unknown'],
            'intent' => ['proposal', 'plan', 'order', 'original-order', 'reflex-order', 'filler-order', 'instance-order', 'option'],
        ],
        'AllergyIntolerance' => [],
        'Immunization' => ['status' => ['completed', 'entered-in-error', 'not-done']],
        'DiagnosticReport' => ['status' => ['registered', 'partial', 'preliminary', 'final', 'amended', 'corrected', 'appended', 'cancelled', 'entered-in-error', 'unknown']],
        'Procedure' => ['status' => ['preparation', 'in-progress', 'not-done', 'on-hold', 'stopped', 'completed', 'entered-in-error', 'unknown']],
        'Encounter' => ['status' => ['planned', 'arrived', 'triaged', 'in-progress', 'onleave', 'finished', 'cancelled', 'entered-in-error', 'unknown']],
        'DocumentReference' => ['status' => ['current', 'superseded', 'entered-in-error']],
        'CarePlan' => [
            'status' => ['draft', 'active', 'on-hold', 'revoked', 'completed', 'entered-in-error', 'unknown'],
            'intent' => ['proposal', 'plan', 'order', 'option'],
        ],
        'Medication' => ['status' => ['active', 'inactive', 'entered-in-error']],
        'Coverage' => ['status' => ['active', 'cancelled', 'draft', 'entered-in-error']],
        'ServiceRequest' => [
            'status' => ['draft', 'active', 'on-hold', 'revoked', 'completed', 'entered-in-error', 'unknown'],
            'intent' => ['proposal', 'plan', 'directive', 'order', 'original-order', 'reflex-order', 'filler-order', 'instance-order', 'option'],
        ],
        'Patient' => ['gender' => ['male', 'female', 'other', 'unknown']],
    ];

    /**
     * US Core conformance check — ONLY runs when the client explicitly asserts
     * a US Core profile for this resource via `meta.profile` (an honest
     * boundary: silent/absent-profile creates keep the existing presence-level
     * badge behavior in usCoreProfile() — no profile claimed, no rejection).
     * When a profile IS asserted, this enforces:
     *   (a) the profile's required must-support elements are present, and
     *   (b) the required-binding value sets in REQUIRED_BINDINGS above for
     *       whichever scalar code fields are present.
     * Slicing, cardinality beyond 1..1/1..*, and CodeableConcept-valued
     * required bindings are explicitly OUT of scope (see REQUIRED_BINDINGS doc).
     *
     * @param array<string, mixed> $payload
     * @return list<string> human-readable violations; empty = conformant
     */
    private const US_CORE_OBSERVATION_LAB_URL = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-lab';

    public static function usCoreConformanceViolations(string $type, array $payload): array
    {
        $assertedProfiles = $payload['meta']['profile'] ?? [];
        if (! is_array($assertedProfiles) || $assertedProfiles === []) {
            return [];
        }

        $knownProfileUrls = array_merge(array_column(self::US_CORE, 'url'), [self::US_CORE_OBSERVATION_LAB_URL]);
        $claimsUsCore = array_intersect($assertedProfiles, $knownProfileUrls) !== [];
        if (! $claimsUsCore) {
            return [];
        }

        $violations = [];

        // Observation isn't in US_CORE (it's profile-selected by category, not a
        // flat extras list — see usCoreProfile()); the lab profile's own must-
        // support extras are status/category/code, mirrored here.
        if ($type === 'Observation') {
            if (in_array(self::US_CORE_OBSERVATION_LAB_URL, $assertedProfiles, true)) {
                foreach (['status', 'category', 'code'] as $element) {
                    if (! array_key_exists($element, $payload) || $payload[$element] === null || $payload[$element] === []) {
                        $violations[] = "Observation.{$element} is required by ".self::US_CORE_OBSERVATION_LAB_URL;
                    }
                }
            }
            foreach (self::REQUIRED_BINDINGS['Observation'] ?? [] as $field => $allowed) {
                $value = $payload[$field] ?? null;
                if (is_string($value) && ! in_array($value, $allowed, true)) {
                    $violations[] = "Observation.{$field} = '{$value}' is not in the required binding [".implode('|', $allowed).']';
                }
            }

            return $violations;
        }

        $def = self::US_CORE[$type] ?? null;
        if ($def === null) {
            return []; // no US Core profile defined for this type at all
        }

        foreach ($def['extras'] as $element) {
            if (! array_key_exists($element, $payload) || $payload[$element] === null || $payload[$element] === []) {
                $violations[] = "{$type}.{$element} is required by ".$def['url'];
            }
        }

        foreach (self::REQUIRED_BINDINGS[$type] ?? [] as $field => $allowed) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && ! in_array($value, $allowed, true)) {
                $violations[] = "{$type}.{$field} = '{$value}' is not in the required binding [".implode('|', $allowed).']';
            }
        }

        return $violations;
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
