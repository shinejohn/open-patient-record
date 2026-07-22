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
    ];

    public static function isSupported(string $type): bool
    {
        return array_key_exists($type, self::REGISTRY);
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
