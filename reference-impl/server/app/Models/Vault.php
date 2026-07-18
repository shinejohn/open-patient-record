<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vault extends Model
{
    use HasUuids;

    protected $fillable = ['subject_user_id'];

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
