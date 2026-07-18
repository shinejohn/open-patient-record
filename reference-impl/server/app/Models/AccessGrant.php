<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccessGrant extends Model
{
    use HasUuids;

    public const PURPOSES = ['treatment', 'personal-share', 'research', 'emergency', 'operations'];

    protected $fillable = [
        'vault_id', 'pseudo_id', 'otp_hash', 'purpose', 'scope', 'permissions',
        'sensitive_categories', 'expires_at', 'max_uses', 'is_emergency', 'emergency_reason',
    ];

    /** The OTP hash must never serialize (offline-guessing oracle otherwise). */
    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'permissions' => 'array',
            'sensitive_categories' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_emergency' => 'boolean',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->uses < $this->max_uses;
    }

    public function allowsWrite(): bool
    {
        return in_array('write', $this->permissions, true);
    }

    public function coversResourceType(string $resourceType): bool
    {
        return in_array('*', $this->scope, true) || in_array($resourceType, $this->scope, true);
    }
}
