<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExportJob extends Model
{
    use HasUuids;

    protected $fillable = ['vault_id', 'requested_by', 'status', 'expires_at'];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'expires_at' => 'datetime',
            'progress' => 'integer',
        ];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function directory(): string
    {
        return "exports/{$this->id}";
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }
}
