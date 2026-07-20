<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delegate acts for the vault subject (spec §2.1 reserved the slot): a parent
 * for a child's vault, an adult child for an elderly parent, a patient-chosen
 * proxy. Delegates hold subject-equivalent authority EXCEPT delegate management
 * itself — only the subject appoints or removes delegates.
 *
 * Deployment-policy note (deliberately out of protocol scope): jurisdiction rules
 * like adolescent-privacy carve-outs are custodian policy layered on top.
 */
final class VaultDelegate extends Model
{
    use HasUuids;

    public const ROLES = ['guardian', 'proxy'];

    protected $fillable = ['vault_id', 'delegate_user_id', 'role', 'added_by'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }
}
