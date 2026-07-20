<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Account recovery — the attack path passkeys displace, so it gets the same
 * no-oracle discipline as grant redemption: identical responses whether or not
 * the account exists; the true story lives only in logs/audit.
 *
 * Reference-impl delivery: the recovery link is written to the application log
 * (mailer integration is deployment-specific). The TOKEN itself is real,
 * hashed-at-rest, single-use, 30-minute TTL, 24h cooldown after a completed
 * recovery.
 */
final class RecoveryController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user !== null && ! $this->inCooldown($user)) {
            // Invalidate any prior outstanding token: one live token at a time.
            DB::table('recovery_tokens')->where('user_id', $user->id)->whereNull('used_at')->delete();

            $plaintext = bin2hex(random_bytes(32));
            DB::table('recovery_tokens')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'token_hash' => Hash::make($plaintext),
                'expires_at' => now()->addMinutes(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('account recovery link issued', ['user_id' => $user->id, 'token' => $plaintext]);
        }

        // Always identical: no account-existence oracle.
        return response()->json(['status' => 'sent']);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        $row = $user === null ? null : DB::table('recovery_tokens')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        // Exactly one hash check regardless of path (timing parity).
        static $dummy = null;
        $dummy ??= Hash::make(bin2hex(random_bytes(16)));
        $valid = Hash::check($data['token'], $row?->token_hash ?? $dummy);

        if ($user === null || $row === null || ! $valid) {
            return response()->json(['error' => 'invalid_recovery'], 403);
        }

        DB::table('recovery_tokens')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);
        $user->forceFill(['password' => $data['password']])->save();

        if (($vault = $user->vault) !== null) {
            $this->audit->record($vault, 'account.recovered', actor: $user);
        }

        return response()->json(['recovered' => true]);
    }

    private function inCooldown(User $user): bool
    {
        return DB::table('recovery_tokens')
            ->where('user_id', $user->id)
            ->whereNotNull('used_at')
            ->where('used_at', '>', now()->subHours(24))
            ->exists();
    }
}
