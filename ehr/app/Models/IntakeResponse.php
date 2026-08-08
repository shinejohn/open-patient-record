<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class IntakeResponse extends Model
{
    use HasUuids;

    protected $fillable = ['practice_id', 'template_id', 'patient_id', 'answers', 'submitted_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['answers' => 'array'];
    }
}
