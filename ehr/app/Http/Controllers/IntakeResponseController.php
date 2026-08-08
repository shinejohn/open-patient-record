<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FormTemplate;
use App\Models\IntakeResponse;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** F4 remainder: patient answers against an intake form template. */
final class IntakeResponseController
{
    public function store(Request $request, string $patientId): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'uuid'],
            'answers' => ['required', 'array'],
        ]);

        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($patientId)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if (! FormTemplate::query()->where('practice_id', $practiceId)->whereKey($data['template_id'])->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $response = IntakeResponse::query()->create([
            'practice_id' => $practiceId,
            'template_id' => $data['template_id'],
            'patient_id' => $patientId,
            'answers' => $data['answers'],
            'submitted_by' => $request->user()->id,
        ]);

        return response()->json(['id' => $response->id, 'template_id' => $response->template_id, 'answers' => $response->answers], 201);
    }

    public function index(Request $request, string $patientId): JsonResponse
    {
        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($patientId)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $responses = IntakeResponse::query()
            ->where('practice_id', $practiceId)
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntakeResponse $r): array => [
                'id' => $r->id,
                'template_id' => $r->template_id,
                'answers' => $r->answers,
                'submitted_by' => $r->submitted_by,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['responses' => $responses]);
    }
}
