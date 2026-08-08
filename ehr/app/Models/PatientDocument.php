<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PatientDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'practice_id', 'patient_id', 'filename_original', 'mime',
        'size_bytes', 'stored_path', 'uploaded_by',
    ];
}
