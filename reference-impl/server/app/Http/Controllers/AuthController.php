<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class AuthController
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $user = User::query()->create($data);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $user->createToken('full')->plainTextToken,
        ], 201);
    }

    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        // Exactly one hash check regardless of path (no account-existence oracle).
        static $dummy = null;
        $dummy ??= Hash::make(bin2hex(random_bytes(16)));
        $valid = Hash::check($data['password'], $user->password ?? $dummy);

        if ($user === null || ! $valid) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        return response()->json(['token' => $user->createToken('full')->plainTextToken]);
    }
}
