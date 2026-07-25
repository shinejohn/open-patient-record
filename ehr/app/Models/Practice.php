<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Practice extends Model
{
    use HasUuids;

    protected $fillable = ['name'];
}
