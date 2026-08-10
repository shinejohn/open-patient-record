<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CallsVaultUnderGrant;
use App\Models\Patient;
use App\Services\VaultBridge;
use App\Services\VaultRejected;
use App\Services\VaultUnreachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Structured clinical capture: vitals, problems, medications, allergies, and
 * immunizations. Each write commits directly to the vault through the FHIR
 * door (VaultBridge) under the practice's treatment grant — exactly the
 * EncounterController pattern. The EHR never stores a second copy of this
 * data locally: reads go through the vault's $everything (PatientController
 * chart) or type search on the vault, never a local table. A vault failure
 * is surfaced honestly (502) — never a fabricated success.
 */
final class ClinicalDataController
{
    use CallsVaultUnderGrant;

    private const LOINC = 'http://loinc.org';

    private const UCUM = 'http://unitsofmeasure.org';

    /** vital key => [loinc code, display, unit, ucum code] */
    private const VITALS = [
        'bp_systolic' => ['8480-6', 'Systolic blood pressure', 'mmHg', 'mm[Hg]'],
        'bp_diastolic' => ['8462-4', 'Diastolic blood pressure', 'mmHg', 'mm[Hg]'],
        'heart_rate' => ['8867-4', 'Heart rate', '/min', '/min'],
        'temperature_c' => ['8310-5', 'Body temperature', 'Cel', 'Cel'],
        'resp_rate' => ['9279-1', 'Respiratory rate', '/min', '/min'],
        'spo2' => ['59408-1', 'Oxygen saturation', '%', '%'],
        'height_cm' => ['8302-2', 'Body height', 'cm', 'cm'],
        'weight_kg' => ['29463-7', 'Body weight', 'kg', 'kg'],
    ];

    public function __construct(private readonly VaultBridge $vault) {}

    /**
     * POST /patients/{id}/vitals — one or more vital-sign readings recorded
     * together are committed as ONE transaction Bundle (all-or-nothing),
     * mirroring how a signed encounter commits its Encounter + note.
     */
    public function storeVitals(Request $request, string $patientId): JsonResponse
    {
        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $rules = [];
        foreach (array_keys(self::VITALS) as $key) {
            $rules[$key] = ['nullable', 'numeric'];
        }
        $data = $request->validate($rules);

        $present = array_filter($data, static fn ($v) => $v !== null);
        if ($present === []) {
            return response()->json([
                'error' => 'no_vitals',
                'message' => 'At least one vital-sign value is required.',
            ], 422);
        }

        $now = now()->toIso8601String();
        $resources = [];
        foreach ($present as $key => $value) {
            [$code, $display, $unit, $ucum] = self::VITALS[$key];
            $resources[] = [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => [[
                    'coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                        'code' => 'vital-signs',
                        'display' => 'Vital Signs',
                    ]],
                ]],
                'code' => [
                    'coding' => [['system' => self::LOINC, 'code' => $code, 'display' => $display]],
                    'text' => $display,
                ],
                'subject' => ['reference' => "Patient/{$patient->vault_id}"],
                'effectiveDateTime' => $now,
                'valueQuantity' => [
                    'value' => $value,
                    'unit' => $unit,
                    'system' => self::UCUM,
                    'code' => $ucum,
                ],
            ];
        }

        return $this->commit($patient, $resources, 'Observation');
    }

    /** POST /patients/{id}/problems — a coded problem (Condition). */
    public function storeProblem(Request $request, string $patientId): JsonResponse
    {
        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'icd10_code' => ['required', 'string', 'max:16'],
            'display' => ['required', 'string', 'max:255'],
            'clinical_status' => ['nullable', 'in:active,resolved'],
            'onset_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $status = $data['clinical_status'] ?? 'active';

        $resource = [
            'resourceType' => 'Condition',
            'clinicalStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                    'code' => $status,
                ]],
            ],
            'code' => [
                'coding' => [[
                    'system' => 'http://hl7.org/fhir/sid/icd-10-cm',
                    'code' => $data['icd10_code'],
                    'display' => $data['display'],
                ]],
                'text' => $data['display'],
            ],
            'subject' => ['reference' => "Patient/{$patient->vault_id}"],
            'recordedDate' => now()->toIso8601String(),
        ];
        if (isset($data['onset_date'])) {
            $resource['onsetDateTime'] = $data['onset_date'];
        }

        return $this->commit($patient, [$resource], 'Condition');
    }

    /**
     * POST /patients/{id}/medications — MedicationStatement (the shape the
     * vault's FhirResourceRegistry accepts: status + subject required, one of
     * medicationCodeableConcept|medicationReference).
     */
    public function storeMedication(Request $request, string $patientId): JsonResponse
    {
        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'medication_display' => ['required', 'string', 'max:255'],
            'rxnorm_code' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,completed,stopped,entered-in-error'],
            'dosage_text' => ['nullable', 'string', 'max:500'],
        ]);

        $medication = ['text' => $data['medication_display']];
        if (isset($data['rxnorm_code'])) {
            $medication['coding'] = [[
                'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
                'code' => $data['rxnorm_code'],
                'display' => $data['medication_display'],
            ]];
        }

        $resource = [
            'resourceType' => 'MedicationStatement',
            'status' => $data['status'] ?? 'active',
            'subject' => ['reference' => "Patient/{$patient->vault_id}"],
            'medicationCodeableConcept' => $medication,
            'effectiveDateTime' => now()->toIso8601String(),
        ];
        if (isset($data['dosage_text'])) {
            $resource['dosage'] = [['text' => $data['dosage_text']]];
        }

        return $this->commit($patient, [$resource], 'MedicationStatement');
    }

    /** POST /patients/{id}/allergies — AllergyIntolerance. */
    public function storeAllergy(Request $request, string $patientId): JsonResponse
    {
        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'substance_display' => ['required', 'string', 'max:255'],
            'substance_code' => ['nullable', 'string', 'max:32'],
            'reaction' => ['nullable', 'string', 'max:500'],
            'criticality' => ['nullable', 'in:low,high,unable-to-assess'],
            'clinical_status' => ['nullable', 'in:active,resolved'],
        ]);

        $code = ['text' => $data['substance_display']];
        if (isset($data['substance_code'])) {
            $code['coding'] = [[
                'system' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
                'code' => $data['substance_code'],
                'display' => $data['substance_display'],
            ]];
        }

        $resource = [
            'resourceType' => 'AllergyIntolerance',
            'clinicalStatus' => [
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                    'code' => $data['clinical_status'] ?? 'active',
                ]],
            ],
            'code' => $code,
            'patient' => ['reference' => "Patient/{$patient->vault_id}"],
            'recordedDate' => now()->toIso8601String(),
        ];
        if (isset($data['criticality'])) {
            $resource['criticality'] = $data['criticality'];
        }
        if (isset($data['reaction'])) {
            $resource['reaction'] = [['manifestation' => [['text' => $data['reaction']]]]];
        }

        return $this->commit($patient, [$resource], 'AllergyIntolerance');
    }

    /** POST /patients/{id}/immunizations — Immunization (CVX code + date). */
    public function storeImmunization(Request $request, string $patientId): JsonResponse
    {
        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'cvx_code' => ['required', 'string', 'max:16'],
            'vaccine_display' => ['required', 'string', 'max:255'],
            'occurrence_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:completed,entered-in-error,not-done'],
        ]);

        $resource = [
            'resourceType' => 'Immunization',
            'status' => $data['status'] ?? 'completed',
            'vaccineCode' => [
                'coding' => [[
                    'system' => 'http://hl7.org/fhir/sid/cvx',
                    'code' => $data['cvx_code'],
                    'display' => $data['vaccine_display'],
                ]],
                'text' => $data['vaccine_display'],
            ],
            'patient' => ['reference' => "Patient/{$patient->vault_id}"],
            'occurrenceDateTime' => $data['occurrence_date'],
        ];

        return $this->commit($patient, [$resource], 'Immunization');
    }

    // ---------------------------------------------------------------

    /**
     * Commit one or more resources of the same type through the vault's FHIR
     * door. A single resource commits directly (commitFhir); several commit
     * as one transaction Bundle (commitBundle) so a multi-reading vitals
     * capture is all-or-nothing. Never fabricates success on a vault error.
     *
     * @param  list<array<string, mixed>>  $resources
     */
    private function commit(Patient $patient, array $resources, string $type): JsonResponse
    {
        $practice = $patient->practice;

        try {
            if (count($resources) === 1) {
                $this->underGrant($this->vault, $patient, fn (string $token) => $this->vault->commitFhir(
                    $token,
                    $patient->vault_id,
                    $type,
                    $resources[0],
                    $practice->name,
                ));

                return response()->json(['committed' => true, 'type' => $type], 201);
            }

            $response = $this->underGrant($this->vault, $patient, fn (string $token): array => $this->vault->commitBundle(
                $token,
                $patient->vault_id,
                $resources,
                $practice->name,
            ));

            $ids = array_map(
                static fn (array $entry): ?string => $entry['resource']['id'] ?? null,
                $response['entry'] ?? [],
            );

            return response()->json(['committed' => true, 'type' => $type, 'ids' => $ids], 201);
        } catch (VaultRejected|VaultUnreachable $e) {
            return response()->json([
                'error' => 'vault_unreachable',
                'message' => "The vault refused the {$type} write; nothing was recorded. ".$e->getMessage(),
            ], 502);
        }
    }

    private function findPatient(Request $request, string $id): ?Patient
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Patient::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
    }
}
