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
        } catch (\App\Services\VaultRejected $e) {
            // A 422 from the vault's registration is a duplicate identity, not
            // an outage — say so. (A linking flow for an existing vault is E2
            // work; today the honest answer is a conflict.)
            if ($e->status === 422 && str_contains($e->path, '/api/users')) {
                return response()->json([
                    'error' => 'already_registered',
                    'message' => 'This person already has an account on the vault server. Linking an existing vault is not yet supported; nothing was saved.',
                ], 409);
            }

            return response()->json(['error' => 'vault_rejected', 'message' => $e->getMessage()], 502);
        } catch (VaultUnreachable $e) {
            return response()->json([
                'error' => 'vault_unreachable',
                'message' => 'The vault could not be provisioned; nothing was saved. '.$e->getMessage(),
            ], 502);
        }

        // Persist IMMEDIATELY once the grant exists: from here everything is
        // recoverable (the grant secret is stored). Review finding: committing
        // demographics before persisting could orphan a live vault whose grant
        // token existed only in this stack frame.
        $patient = Patient::query()->create([
            'practice_id' => $practice->id,
            'vault_id' => $provisioned['vault_id'],
            'vault_user_id' => $provisioned['vault_user_id'],
            'grant_pseudo_id' => $provisioned['grant_pseudo_id'],
            'grant_otp' => $provisioned['grant_otp'],
            'grant_token' => $provisioned['grant_token'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
        ]);

        // Commit initial demographics through the FHIR door. Failure here is
        // REPORTED, never hidden: the roster row exists and the commit can be
        // retried, so the honest shape is 201 + demographics_committed=false.
        $demographicsCommitted = true;
        try {
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
        } catch (\App\Services\VaultRejected|VaultUnreachable) {
            $demographicsCommitted = false;
        }

        return response()->json([
            'id' => $patient->id,
            'vault_id' => $patient->vault_id,
            'demographics_committed' => $demographicsCommitted,
        ], 201);
    }

    /** GET /api/patients/{id}/chart — the vault's $everything under the grant. */
    public function chart(Request $request, string $id): JsonResponse
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            // Malformed ids must not become a PostgreSQL cast error (a 500 is a
            // louder oracle than the 404 this endpoint promises).
            return response()->json(['error' => 'not_found'], 404);
        }

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
        } catch (\App\Services\VaultRejected $e) {
            // Derived tokens expire ~30 min after redemption. Auth failure means
            // re-redeem the grant secret for a fresh token and retry ONCE.
            if (in_array($e->status, [401, 403], true) && $patient->grant_otp !== null) {
                try {
                    $fresh = $this->vault->redeemGrant($patient->grant_pseudo_id, $patient->grant_otp);
                    $patient->update(['grant_token' => $fresh]);

                    return response()->json($this->vault->everything($fresh, $patient->vault_id));
                } catch (\App\Services\VaultRejected|VaultUnreachable $retry) {
                    // Grant revoked, expired, or uses exhausted — honest 502
                    // with the reason; the patient's revocation must stick.
                    return response()->json(['error' => 'vault_rejected', 'message' => $retry->getMessage()], 502);
                }
            }

            return response()->json(['error' => 'vault_rejected', 'message' => $e->getMessage()], 502);
        } catch (VaultUnreachable $e) {
            return response()->json(['error' => 'vault_unreachable', 'message' => $e->getMessage()], 502);
        }
    }
}
