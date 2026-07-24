<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TermCode extends Model
{
    use HasUuids;

    protected $fillable = ['system', 'code', 'display'];
}
