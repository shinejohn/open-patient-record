<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2: terminology storage. Code systems are NOT PHI — this table holds public
 * vocabulary (CVX/ICD-10-CM) and operator-imported licensed vocabularies
 * (LOINC, RxNorm), keyed by (system, code). Lookups are local; terminology
 * resolution never causes egress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('system', 255);
            $table->string('code', 64);
            $table->text('display');
            $table->timestampsTz();

            $table->unique(['system', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_codes');
    }
};
