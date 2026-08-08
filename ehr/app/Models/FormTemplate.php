<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class FormTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['practice_id', 'name', 'fields', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fields' => 'array'];
    }
}
