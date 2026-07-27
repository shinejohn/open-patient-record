<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Encounter extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_id', 'patient_id', 'clinician_id', 'appointment_id', 'status',
        'subjective', 'objective', 'assessment', 'plan', 'started_at', 'signed_at',
        'vault_encounter_entry_id', 'vault_note_entry_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'signed_at' => 'datetime',
            'subjective' => 'encrypted',
            'objective' => 'encrypted',
            'assessment' => 'encrypted',
            'plan' => 'encrypted',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
