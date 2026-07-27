<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Appointment extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_id', 'patient_id', 'clinician_id', 'starts_at', 'ends_at', 'status', 'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reason' => 'encrypted',
        ];
    }
}
