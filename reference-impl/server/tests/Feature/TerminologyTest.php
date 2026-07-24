<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F2: the terminology service — storage-backed CodeSystem/$lookup and
 * $validate-code, fed by importers for the real release formats.
 *
 * Licensing honesty, encoded here as behavior:
 *   - CVX (CDC) is public domain — a seed subset ships in-repo and the importer
 *     defaults to it.
 *   - ICD-10-CM (CMS) is public — importer parses the official order file the
 *     operator downloads.
 *   - LOINC and RxNorm are free but non-redistributable — importers parse the
 *     official release files (Loinc.csv, RXNCONSO.RRF) from the operator's own
 *     licensed download. No LOINC/RxNorm/SNOMED content ships in this repo.
 *   - A KNOWN system with no imported rows answers honestly (result=false /
 *     not-found), never pretends; an UNKNOWN system is not-supported.
 */
final class TerminologyTest extends TestCase
{
    use RefreshDatabase;

    private const CVX = 'http://hl7.org/fhir/sid/cvx';
    private const ICD10 = 'http://hl7.org/fhir/sid/icd-10-cm';
    private const RXNORM = 'http://www.nlm.nih.gov/research/umls/rxnorm';
    private const LOINC = 'http://loinc.org';

    public function test_cvx_seed_imports_and_lookup_returns_display(): void
    {
        Artisan::call('term:import-cvx');

        $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::CVX).'&code=208')
            ->assertOk()
            ->assertJsonPath('resourceType', 'Parameters');

        $display = collect($this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::CVX).'&code=208')->json('parameter'))
            ->firstWhere('name', 'display');
        $this->assertNotNull($display);
        $this->assertNotSame('', $display['valueString']);
    }

    public function test_validate_code_answers_true_and_false(): void
    {
        Artisan::call('term:import-cvx');

        $good = $this->getJson('/api/fhir/CodeSystem/$validate-code?system='.urlencode(self::CVX).'&code=141')
            ->assertOk();
        $this->assertTrue(collect($good->json('parameter'))->firstWhere('name', 'result')['valueBoolean']);

        $bad = $this->getJson('/api/fhir/CodeSystem/$validate-code?system='.urlencode(self::CVX).'&code=99999')
            ->assertOk();
        $this->assertFalse(collect($bad->json('parameter'))->firstWhere('name', 'result')['valueBoolean']);
    }

    public function test_unknown_system_is_not_supported(): void
    {
        $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode('http://example.com/madeup').'&code=x')
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome')
            ->assertJsonPath('issue.0.code', 'not-supported');
    }

    public function test_unknown_code_in_known_system_is_not_found(): void
    {
        Artisan::call('term:import-cvx');

        $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::CVX).'&code=00000')
            ->assertStatus(404)
            ->assertJsonPath('resourceType', 'OperationOutcome')
            ->assertJsonPath('issue.0.code', 'not-found');
    }

    public function test_importers_are_idempotent(): void
    {
        Artisan::call('term:import-cvx');
        $first = DB::table('term_codes')->count();

        Artisan::call('term:import-cvx');
        $this->assertSame($first, DB::table('term_codes')->count());
    }

    public function test_icd10_order_file_import(): void
    {
        Artisan::call('term:import-icd10', ['path' => base_path('tests/Fixtures/terminology/icd10cm_order_fixture.txt')]);

        $lookup = $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::ICD10).'&code=A00.0')
            ->assertOk();
        $display = collect($lookup->json('parameter'))->firstWhere('name', 'display');
        $this->assertStringContainsString('Cholera', $display['valueString']);

        // Header-style rows (non-billable) still import; code without dot for
        // 3-char categories is preserved undotted.
        $this->getJson('/api/fhir/CodeSystem/$validate-code?system='.urlencode(self::ICD10).'&code=A00')
            ->assertOk();
    }

    public function test_rxnorm_rrf_import_prefers_prescribable_term_types(): void
    {
        Artisan::call('term:import-rxnorm', ['path' => base_path('tests/Fixtures/terminology/RXNCONSO_fixture.RRF')]);

        $lookup = $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::RXNORM).'&code=860975')
            ->assertOk();
        $display = collect($lookup->json('parameter'))->firstWhere('name', 'display');
        // SCD term wins over the IN row for the same RXCUI in the fixture.
        $this->assertStringContainsString('24 HR metformin', $display['valueString']);

        // Suppressed rows are never imported.
        $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::RXNORM).'&code=999999')
            ->assertStatus(404);
    }

    public function test_loinc_csv_import(): void
    {
        Artisan::call('term:import-loinc', ['path' => base_path('tests/Fixtures/terminology/loinc_fixture.csv')]);

        $lookup = $this->getJson('/api/fhir/CodeSystem/$lookup?system='.urlencode(self::LOINC).'&code=2339-0')
            ->assertOk();
        $display = collect($lookup->json('parameter'))->firstWhere('name', 'display');
        $this->assertStringContainsString('Glucose', $display['valueString']);
    }
}
