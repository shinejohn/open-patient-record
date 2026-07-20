<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserCredential extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'credential_id', 'record', 'sign_count', 'nickname', 'last_used_at'];

    /** The serialized credential record contains the public key — never expose raw. */
    protected $hidden = ['record'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'sign_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
