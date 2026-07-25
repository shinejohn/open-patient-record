<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The practice's link to a patient whose record lives in an OPR vault.
 *
 * Direct identifiers and the grant token use encrypted casts. There is no
 * subject-credential field ON PURPOSE — the practice's access to the vault is
 * a scoped, revocable treatment grant, never the patient's own key (see
 * PatientProvisioningTest for the custody rationale).
 */
final class Patient extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_id', 'vault_id', 'vault_user_id', 'grant_pseudo_id',
        'grant_token', 'grant_otp', 'name', 'email', 'birth_date', 'gender',
    ];

    protected $hidden = ['grant_token', 'grant_otp'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grant_token' => 'encrypted',
            'grant_otp' => 'encrypted',
            'name' => 'encrypted',
            'email' => 'encrypted',
            'birth_date' => 'encrypted',
        ];
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(Practice::class);
    }
}
