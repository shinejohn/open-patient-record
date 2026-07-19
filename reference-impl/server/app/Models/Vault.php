<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\EnvelopeCrypto;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

final class Vault extends Model
{
    use HasUuids;

    protected $fillable = ['subject_user_id'];

    /** The wrapped key must never serialize. */
    protected $hidden = ['wrapped_dek'];

    /** @var array<string, string> in-process DEK cache (one unwrap per vault per request) */
    private static array $dekCache = [];

    /**
     * This vault's data-encryption key, unwrapping (or lazily creating) it.
     * The wrap uses the app master key — the KMS integration point (docs: key mgmt).
     */
    public static function dekFor(string $vaultId): string
    {
        return self::$dekCache[$vaultId] ??= self::resolveDek($vaultId);
    }

    public function dek(): string
    {
        return self::dekFor($this->id);
    }

    private static function resolveDek(string $vaultId): string
    {
        /** @var Vault $vault */
        $vault = self::query()->whereKey($vaultId)->firstOrFail();

        if ($vault->wrapped_dek === null) {
            $dek = EnvelopeCrypto::generateKey();
            $vault->forceFill(['wrapped_dek' => Crypt::encryptString(base64_encode($dek))])->save();

            return $dek;
        }

        $dek = base64_decode(Crypt::decryptString($vault->wrapped_dek), true);
        if ($dek === false) {
            throw new \RuntimeException('OPR: corrupt wrapped DEK.');
        }

        return $dek;
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VaultEntry::class)->orderBy('seq');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class)->orderBy('created_at');
    }

    public function isSubject(User $user): bool
    {
        return $this->subject_user_id === $user->id;
    }
}
