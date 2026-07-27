<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** E2: scheduling. Overlap for the same clinician is refused; abutting is fine. */
final class AppointmentController
{
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::query()
            ->where('practice_id', $request->user()->practice_id)
            ->where('status', 'booked')
            ->orderBy('starts_at');

        if (is_string($date = $request->query('date')) && $date !== '') {
            $query->whereDate('starts_at', $date);
        }

        return response()->json(['appointments' => $query->get()->map(fn (Appointment $a): array => [
            'id' => $a->id,
            'patient_id' => $a->patient_id,
            'clinician_id' => $a->clinician_id,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'reason' => $a->reason,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'uuid'],
            'clinician_id' => ['nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($data['patient_id'])->exists()) {
            return response()->json(['error' => 'not_found'], 404); // cross-practice = nonexistent
        }

        $clinicianId = $data['clinician_id'] ?? $request->user()->id;

        // Parse BEFORE querying: raw ISO strings ('...T09:15:00Z') compared
        // against driver-formatted storage ('... 09:15:00') silently miss
        // overlaps on string order. Carbon instances always bind correctly.
        $startsAt = \Illuminate\Support\Carbon::parse($data['starts_at']);
        $endsAt = \Illuminate\Support\Carbon::parse($data['ends_at']);

        $overlaps = Appointment::query()
            ->where('practice_id', $practiceId)
            ->where('clinician_id', $clinicianId)
            ->where('status', 'booked')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
        if ($overlaps) {
            return response()->json([
                'error' => 'overlap',
                'message' => 'The clinician already has an appointment in that window.',
            ], 422);
        }

        $appointment = Appointment::query()->create([
            'practice_id' => $practiceId,
            'patient_id' => $data['patient_id'],
            'clinician_id' => $clinicianId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['id' => $appointment->id], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
        if ($appointment === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $appointment->update(['status' => 'cancelled']);

        return response()->json(['id' => $appointment->id, 'status' => 'cancelled']);
    }
}
