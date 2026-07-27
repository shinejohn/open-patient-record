<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** E2: staff onboarding — owner-only (authority over roles never delegates). */
final class StaffController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', 'in:owner,clinician,staff'],
        ]);

        $user = User::query()->create($data + ['practice_id' => $request->user()->practice_id]);

        return response()->json([
            'user' => ['id' => $user->id, 'role' => $user->role],
            'token' => $user->createToken('ehr')->plainTextToken,
        ], 201);
    }
}
