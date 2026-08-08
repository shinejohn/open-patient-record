<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FormTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F4 remainder: intake form templates. Owner-authored (they define the
 * practice's intake process); any practice member can list them, matching
 * the roster/scheduling routes' role posture.
 */
final class FormTemplateController
{
    public function index(Request $request): JsonResponse
    {
        $templates = FormTemplate::query()
            ->where('practice_id', $request->user()->practice_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FormTemplate $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'fields' => $t->fields,
            ]);

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fields' => ['present', 'array'],
        ]);

        $template = FormTemplate::query()->create([
            'practice_id' => $request->user()->practice_id,
            'name' => $data['name'],
            'fields' => $data['fields'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['id' => $template->id, 'name' => $template->name, 'fields' => $template->fields], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'fields' => ['sometimes', 'array'],
        ]);

        $template = FormTemplate::query()
            ->where('practice_id', $request->user()->practice_id)
            ->whereKey($id)
            ->first();
        if ($template === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $template->update($data);

        return response()->json(['id' => $template->id, 'name' => $template->name, 'fields' => $template->fields]);
    }
}
