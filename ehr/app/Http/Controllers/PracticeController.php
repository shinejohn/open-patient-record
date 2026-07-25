<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Practice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PracticeController
{
    /** POST /api/register-practice — bootstrap a practice and its owner. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'practice_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        [$user, $practice] = DB::transaction(function () use ($data): array {
            $practice = Practice::query()->create(['name' => $data['practice_name']]);
            $user = User::query()->create([
                'practice_id' => $practice->id,
                'role' => 'owner',
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            return [$user, $practice];
        });

        return response()->json([
            'practice' => ['id' => $practice->id, 'name' => $practice->name],
            'user' => ['id' => $user->id, 'role' => $user->role],
            'token' => $user->createToken('ehr')->plainTextToken,
        ], 201);
    }
}
