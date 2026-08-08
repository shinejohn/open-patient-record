<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 remainder: document upload. The client filename is NEVER trusted as a
 * storage path (path traversal + collision hazard) — it is kept only as
 * metadata; the stored file is named with a hash-derived id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->string('filename_original');
            $table->string('mime');
            $table->integer('size_bytes');
            $table->string('stored_path');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['practice_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
