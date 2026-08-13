<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A SMART Backend Services (system-to-system) client, registered against
 * exactly one vault. We hold only its PUBLIC signing key (JWK); the client
 * proves possession of the matching private key by signing a fresh
 * client_assertion JWT on every token request.
 */
final class BackendClient extends Model
{
    use HasUuids;

    protected $fillable = ['vault_id', 'name', 'jwk', 'jwks_uri', 'scope', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'jwk' => 'array',
            'scope' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function coversResourceType(string $resourceType): bool
    {
        return in_array('*', $this->scope, true) || in_array($resourceType, $this->scope, true);
    }
}
