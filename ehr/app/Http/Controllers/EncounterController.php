<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CallsVaultUnderGrant;
use App\Models\Encounter;
use App\Models\Patient;
use App\Services\VaultBridge;
use App\Services\VaultRejected;
use App\Services\VaultUnreachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E2: encounters — SOAP drafts, then sign. Signing composes an Encounter and a
 * DocumentReference (the note) and commits them as ONE transaction Bundle
 * through the FHIR door: all-or-nothing, so a half-signed visit cannot exist
 * on the chain. A signed encounter is locally immutable (corrections are new
 * documents, mirroring the vault's supersession model). A vault failure leaves
 * the encounter honestly in draft — never "signed" with nothing behind it.
 */
final class EncounterController
{
    use CallsVaultUnderGrant;

    public function __construct(private readonly VaultBridge $vault)
    {
    }

    public function store(Request $request, string $patientId): JsonResponse
    {
        $data = $request->validate([
            'subjective' => ['nullable', 'string', 'max:20000'],
            'objective' => ['nullable', 'string', 'max:20000'],
            'assessment' => ['nullable', 'string', 'max:20000'],
            'plan' => ['nullable', 'string', 'max:20000'],
            'appointment_id' => ['nullable', 'uuid'],
        ]);

        $patient = $this->findPatient($request, $patientId);
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $encounter = Encounter::query()->create($data + [
            'practice_id' => $request->user()->practice_id,
            'patient_id' => $patient->id,
            'clinician_id' => $request->user()->id,
            'status' => 'draft', // explicit: DB defaults don't hydrate the model
            'started_at' => now(),
        ]);

        return response()->json(['id' => $encounter->id, 'status' => $encounter->status], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $encounter = $this->findEncounter($request, $id);
        if ($encounter === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($encounter->status === 'signed') {
            return response()->json([
                'error' => 'signed_immutable',
                'message' => 'Signed encounters are immutable; record an addendum as a new note.',
            ], 409);
        }

        $encounter->update($request->validate([
            'subjective' => ['nullable', 'string', 'max:20000'],
            'objective' => ['nullable', 'string', 'max:20000'],
            'assessment' => ['nullable', 'string', 'max:20000'],
            'plan' => ['nullable', 'string', 'max:20000'],
        ]));

        return response()->json(['id' => $encounter->id, 'status' => $encounter->status]);
    }

    public function sign(Request $request, string $id): JsonResponse
    {
        $encounter = $this->findEncounter($request, $id);
        if ($encounter === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($encounter->status === 'signed') {
            return response()->json(['id' => $encounter->id, 'status' => 'signed']); // idempotent
        }

        $patient = $encounter->patient;
        $practice = $request->user()->practice;

        $note = implode("\n\n", array_filter([
            $encounter->subjective !== null ? "S: {$encounter->subjective}" : null,
            $encounter->objective !== null ? "O: {$encounter->objective}" : null,
            $encounter->assessment !== null ? "A: {$encounter->assessment}" : null,
            $encounter->plan !== null ? "P: {$encounter->plan}" : null,
        ]));

        $resources = [
            [
                'resourceType' => 'Encounter',
                'status' => 'finished',
                'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB'],
                'subject' => ['reference' => "Patient/{$patient->vault_id}"],
                'period' => [
                    'start' => $encounter->started_at->toIso8601String(),
                    'end' => now()->toIso8601String(),
                ],
            ],
            [
                'resourceType' => 'DocumentReference',
                'status' => 'current',
                'type' => ['text' => 'Progress note'],
                'subject' => ['reference' => "Patient/{$patient->vault_id}"],
                'date' => now()->toIso8601String(),
                'content' => [[
                    'attachment' => [
                        'contentType' => 'text/plain',
                        'data' => base64_encode($note),
                    ],
                ]],
            ],
        ];

        try {
            $response = $this->underGrant($this->vault, $patient, fn (string $token): array => $this->vault->commitBundle(
                $token,
                $patient->vault_id,
                $resources,
                $practice->name,
            ));
        } catch (VaultRejected|VaultUnreachable $e) {
            return response()->json([
                'error' => 'vault_unreachable',
                'message' => 'The vault refused the signed note; the encounter remains in draft. '.$e->getMessage(),
            ], 502);
        }

        $encounter->update([
            'status' => 'signed',
            'signed_at' => now(),
            'vault_encounter_entry_id' => $response['entry'][0]['resource']['id'] ?? null,
            'vault_note_entry_id' => $response['entry'][1]['resource']['id'] ?? null,
        ]);

        return response()->json(['id' => $encounter->id, 'status' => 'signed']);
    }

    private function findPatient(Request $request, string $id): ?Patient
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            return null;
        }

        return Patient::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
    }

    private function findEncounter(Request $request, string $id): ?Encounter
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            return null;
        }

        return Encounter::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
    }
}
