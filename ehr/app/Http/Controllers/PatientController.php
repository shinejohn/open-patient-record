<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\VaultBridge;
use App\Services\VaultUnreachable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E1: patient registration provisions a vault; the chart is read from the
 * vault under the practice's treatment grant. The practice-ops DB never holds
 * clinical data — it holds the roster and the grant.
 */
final class PatientController
{
    public function __construct(private readonly VaultBridge $vault)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            ->where('practice_id', $request->user()->practice_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Patient $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'vault_id' => $p->vault_id,
                'gender' => $p->gender,
            ]);

        return response()->json(['patients' => $patients]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', 'in:female,male,other,unknown'],
        ]);

        $practice = $request->user()->practice;

        try {
            $provisioned = $this->vault->provision(
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
                $practice->name,
            );

            // Commit initial demographics through the FHIR door, attributed to
            // the practice. Runs before local persist: if the vault refuses,
            // no roster row pretends the patient exists.
            $resource = array_filter([
                'resourceType' => 'Patient',
                'name' => [['text' => $data['name']]],
                'birthDate' => $data['birth_date'] ?? null,
                'gender' => $data['gender'] ?? null,
            ], static fn ($v) => $v !== null);

            $this->vault->commitFhir(
                $provisioned['grant_token'],
                $provisioned['vault_id'],
                'Patient',
                $resource,
                $practice->name,
            );
        } catch (VaultUnreachable $e) {
            return response()->json([
                'error' => 'vault_unreachable',
                'message' => 'The vault could not be provisioned; nothing was saved. '.$e->getMessage(),
            ], 502);
        }

        $patient = Patient::query()->create([
            'practice_id' => $practice->id,
            'vault_id' => $provisioned['vault_id'],
            'vault_user_id' => $provisioned['vault_user_id'],
            'grant_pseudo_id' => $provisioned['grant_pseudo_id'],
            'grant_token' => $provisioned['grant_token'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
        ]);

        return response()->json(['id' => $patient->id, 'vault_id' => $patient->vault_id], 201);
    }

    /** GET /api/patients/{id}/chart — the vault's $everything under the grant. */
    public function chart(Request $request, string $id): JsonResponse
    {
        /** @var Patient|null $patient */
        $patient = Patient::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();

        if ($patient === null) {
            // Cross-practice ids are indistinguishable from nonexistent — no oracle.
            return response()->json(['error' => 'not_found'], 404);
        }

        try {
            return response()->json($this->vault->everything($patient->grant_token, $patient->vault_id));
        } catch (VaultUnreachable $e) {
            return response()->json(['error' => 'vault_unreachable', 'message' => $e->getMessage()], 502);
        }
    }
}
