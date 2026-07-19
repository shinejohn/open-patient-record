<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use SensitiveParameter;

final class GrantService
{
    /** Derived-token lifetime cap: 30 minutes (spec §3.3). */
    private const TOKEN_TTL_MINUTES = 30;

    private static ?string $dummyHash = null;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Mint a grant (spec §3.1). Controller enforces subject-only. Returns the grant
     * and the plaintext one-time secret — shown exactly once, only its hash stored.
     *
     * @param array{purpose: string, scope: array<string>, permissions: array<string>,
     *              sensitive_categories?: array<string>, expires_in_minutes?: int,
     *              max_uses?: int, emergency_reason?: ?string} $data
     * @return array{grant: AccessGrant, otp: string}
     */
    public function mint(Vault $vault, User $subject, array $data): array
    {
        if (! in_array($data['purpose'], AccessGrant::PURPOSES, true)) {
            throw new InvalidArgumentException('Invalid purpose.');
        }
        if ($data['purpose'] === 'emergency' && empty($data['emergency_reason'])) {
            throw new InvalidArgumentException('Emergency grants require a recorded reason (spec §3.7).');
        }
        $permissions = $data['permissions'];
        if ($permissions === [] || array_diff($permissions, ['read', 'write']) !== []) {
            throw new InvalidArgumentException('Permissions must be read and/or write.');
        }

        $otp = (string) random_int(10000000, 99999999);

        $grant = AccessGrant::query()->create([
            'vault_id' => $vault->id,
            'pseudo_id' => bin2hex(random_bytes(16)), // random, never derived (spec §3.3)
            'otp_hash' => Hash::make($otp),
            'purpose' => $data['purpose'],
            'scope' => $data['scope'],
            'permissions' => $permissions,
            'sensitive_categories' => $data['sensitive_categories'] ?? [],
            'expires_at' => now()->addMinutes($data['expires_in_minutes'] ?? 60 * 24),
            'max_uses' => $data['max_uses'] ?? 1,
            'is_emergency' => $data['purpose'] === 'emergency',
            'emergency_reason' => $data['emergency_reason'] ?? null,
        ]);

        $this->audit->record($vault, 'grant.minted', actor: $subject, grant: $grant, context: [
            'purpose' => $grant->purpose,
            'scope' => $grant->scope,
            'permissions' => $grant->permissions,
        ]);

        return ['grant' => $grant, 'otp' => $otp];
    }

    /**
     * Redeem a grant for a short-lived token (spec §3.3). Fail-closed; no
     * enumeration or timing oracle: exactly one Hash::check runs whether or not the
     * pseudo_id exists, and every failure returns null (controller maps all failures
     * to one identical response). The true reason goes only to the audit log.
     */
    public function redeem(string $pseudoId, #[SensitiveParameter] string $otp): ?array
    {
        try {
            return DB::transaction(function () use ($pseudoId, $otp): ?array {
                /** @var AccessGrant|null $grant */
                $grant = AccessGrant::query()
                    ->where('pseudo_id', $pseudoId)
                    ->lockForUpdate()
                    ->first();

                $hash = $grant?->otp_hash ?? self::dummyHash();
                $otpValid = Hash::check($otp, $hash);

                if ($grant === null) {
                    return null; // dummy check already ran — timing-indistinguishable
                }

                $failure = match (true) {
                    ! $otpValid => 'bad_otp',
                    $grant->revoked_at !== null => 'revoked',
                    ! $grant->expires_at->isFuture() => 'expired',
                    $grant->uses >= $grant->max_uses => 'uses_exhausted',
                    default => null,
                };

                if ($failure !== null) {
                    $this->audit->record($grant->vault, 'grant.denied', grant: $grant, reason: $failure);

                    return null;
                }

                $grant->forceFill(['uses' => $grant->uses + 1])->save();

                $abilities = array_merge(
                    ["grant:{$grant->id}", "vault:{$grant->vault_id}", "purpose:{$grant->purpose}"],
                    $grant->permissions,
                );

                $expiresAt = min(
                    now()->addMinutes(self::TOKEN_TTL_MINUTES),
                    $grant->expires_at,
                );

                $token = $grant->vault->subject->createToken(
                    "grant:{$grant->id}",
                    $abilities,
                    $expiresAt,
                );

                $this->audit->record($grant->vault, 'grant.redeemed', grant: $grant);

                return [
                    'token' => $token->plainTextToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'vault_id' => $grant->vault_id,
                    'scope' => $grant->scope,
                    'permissions' => $grant->permissions,
                    'purpose' => $grant->purpose,
                ];
            });
        } catch (\Throwable) {
            return null; // fail-closed (spec §3.5)
        }
    }

    /**
     * ShareSession (spec §3.6): an AccessGrant constrained to read-only,
     * pre-selected scope, lifetime ≤ 60 minutes, single redemption. The QR-at-
     * check-in flow. Constraints are enforced HERE, not trusted from the caller.
     *
     * @param array{scope?: array<string>, sensitive_categories?: array<string>,
     *              expires_in_minutes?: int} $data
     * @return array{grant: AccessGrant, otp: string, share_code: string}
     */
    public function mintShareSession(Vault $vault, User $subject, array $data): array
    {
        $result = $this->mint($vault, $subject, [
            'purpose' => 'personal-share',
            'scope' => $data['scope'] ?? ['*'],
            'permissions' => ['read'],                              // never write
            'sensitive_categories' => $data['sensitive_categories'] ?? [],
            'expires_in_minutes' => min($data['expires_in_minutes'] ?? 15, 60), // hard cap
            'max_uses' => 1,                                        // single redemption
        ]);

        // The QR payload: everything the receiving device needs to redeem once.
        $result['share_code'] = sprintf(
            'opr-share:v1:%s:%s',
            $result['grant']->pseudo_id,
            $result['otp'],
        );

        return $result;
    }

    /**
     * Break-glass (spec §3.7): emergency access WITHOUT the subject's participation.
     * Requires recorded accessor identity + reason; excludes sensitive categories;
     * short-lived; flagged is_emergency in the audit trail the subject can read.
     * The token is issued directly — an emergency has no OTP hand-off step.
     *
     * @return array{token: string, expires_at: string, grant_id: string}
     */
    public function breakGlass(Vault $vault, User $accessor, string $reason): array
    {
        $grant = AccessGrant::query()->create([
            'vault_id' => $vault->id,
            'pseudo_id' => bin2hex(random_bytes(16)),
            // Never OTP-redeemable: random hash no one holds the preimage of.
            'otp_hash' => Hash::make(bin2hex(random_bytes(32))),
            'purpose' => 'emergency',
            'scope' => ['*'],
            'permissions' => ['read'],
            'sensitive_categories' => [],   // spec §3.7: sensitive excluded
            'expires_at' => now()->addMinutes(60),
            'max_uses' => 1,
            'is_emergency' => true,
            'emergency_reason' => $reason,
        ]);
        $grant->forceFill(['uses' => 1])->save();

        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $token = $vault->subject->createToken(
            "grant:{$grant->id}",
            ["grant:{$grant->id}", "vault:{$vault->id}", 'purpose:emergency', 'read'],
            $expiresAt,
        );

        $this->audit->record($vault, 'grant.emergency_access', actor: $accessor, grant: $grant, context: [
            'accessor_user_id' => $accessor->id,
            'accessor_name' => $accessor->name,
        ], reason: $reason);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'grant_id' => $grant->id,
        ];
    }

    /**
     * Revoke (spec §3.4): refuse new redemptions immediately AND invalidate
     * outstanding derived tokens — this implementation deletes them, beating the
     * 5-minute bound with immediacy.
     */
    public function revoke(AccessGrant $grant, User $subject): void
    {
        DB::transaction(function () use ($grant, $subject): void {
            if ($grant->revoked_at === null) {
                $grant->forceFill(['revoked_at' => now()])->save();
            }

            PersonalAccessToken::query()->where('name', "grant:{$grant->id}")->delete();

            $this->audit->record($grant->vault, 'grant.revoked', actor: $subject, grant: $grant);
        });
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)));
    }
}
