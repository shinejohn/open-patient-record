<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F4 remainder: document upload. The client filename is metadata only — it
 * is NEVER used as the storage path (path traversal / collision hazard); the
 * stored file always gets a hash-derived name on the local disk.
 */
final class DocumentController
{
    private const MAX_KB = 10240; // 10MB

    /** @var array<string, string> mime => extension */
    private const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function store(Request $request, string $patientId): JsonResponse
    {
        $practiceId = $request->user()->practice_id;
        $patient = Patient::query()->where('practice_id', $practiceId)->whereKey($patientId)->first();
        if ($patient === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_KB,
                'mimetypes:'.implode(',', array_keys(self::ALLOWED_MIMES)),
            ],
        ]);

        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        if (! array_key_exists($mime, self::ALLOWED_MIMES)) {
            return response()->json([
                'error' => 'unsupported_mime',
                'message' => 'Only PDF, JPEG, and PNG files are accepted.',
            ], 422);
        }

        $hashedName = Str::uuid()->toString().'.'.self::ALLOWED_MIMES[$mime];
        $storedPath = $file->storeAs("documents/{$patientId}", $hashedName, 'local');

        $document = PatientDocument::query()->create([
            'practice_id' => $practiceId,
            'patient_id' => $patientId,
            'filename_original' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
            'stored_path' => $storedPath,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $document->id,
            'filename_original' => $document->filename_original,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'stored_path' => $document->stored_path,
        ], 201);
    }

    public function index(Request $request, string $patientId): JsonResponse
    {
        $practiceId = $request->user()->practice_id;
        if (! Patient::query()->where('practice_id', $practiceId)->whereKey($patientId)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $documents = PatientDocument::query()
            ->where('practice_id', $practiceId)
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PatientDocument $d): array => [
                'id' => $d->id,
                'filename_original' => $d->filename_original,
                'mime' => $d->mime,
                'size_bytes' => $d->size_bytes,
                'created_at' => $d->created_at->toIso8601String(),
            ]);

        return response()->json(['documents' => $documents]);
    }

    public function download(Request $request, string $id): JsonResponse|StreamedResponse
    {
        $document = PatientDocument::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
        if ($document === null || ! Storage::disk('local')->exists($document->stored_path)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return Storage::disk('local')->download($document->stored_path, $document->filename_original, [
            'Content-Type' => $document->mime,
        ]);
    }
}
