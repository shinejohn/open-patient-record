<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 remainder: patient intake forms. Templates are practice-defined
 * (name + a JSON field list); responses capture a patient's answers against
 * a template. Templates are owner-authored (they define the practice's
 * intake process); responses can be recorded by any practice member who can
 * see the patient, matching the roster/scheduling routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->string('name');
            $table->json('fields'); // list<{key, label, type, required}>
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('intake_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('practice_id')->constrained('practices');
            $table->foreignUuid('template_id')->constrained('form_templates');
            $table->foreignUuid('patient_id')->constrained('patients');
            $table->json('answers');
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['practice_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_responses');
        Schema::dropIfExists('form_templates');
    }
};
