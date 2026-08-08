<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_id', 'patient_id', 'plan_name', 'amount_cents',
        'interval', 'anchor_day', 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
