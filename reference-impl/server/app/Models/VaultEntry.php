<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A committed vault entry. Append-only across four layers:
 * PG row trigger (UPDATE/DELETE), PG statement trigger (TRUNCATE),
 * RESTRICT foreign keys, and the model-level guards below (spec §4.1).
 */
final class VaultEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TIERS = ['verified-source', 'clinician-verified', 'unverified-import'];

    protected $fillable = [
        'vault_id', 'seq', 'resource_type', 'payload', 'verification_tier',
        'sensitive_category', 'replaces_entry_id', 'content_hash', 'chain_hash',
        'contributor_user_id', 'provenance',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'provenance' => 'array',
            'seq' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributor_user_id');
    }

    /** @param array<mixed> $options */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Vault entries are append-only (OPR spec §4.1). Commit a superseding entry instead.');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Vault entries are append-only (OPR spec §4.1).');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Vault entries are append-only (OPR spec §4.1).');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new RuntimeException('Vault entries are append-only (OPR spec §4.1).');
    }
}
