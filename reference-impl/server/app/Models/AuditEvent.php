<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

final class AuditEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'vault_id', 'actor_user_id', 'grant_id', 'action', 'purpose',
        'is_emergency', 'reason', 'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'is_emergency' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @param array<mixed> $options */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('Audit events are append-only (OPR spec §6).');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Audit events are append-only (OPR spec §6).');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Audit events are append-only (OPR spec §6).');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new RuntimeException('Audit events are append-only (OPR spec §6).');
    }
}
