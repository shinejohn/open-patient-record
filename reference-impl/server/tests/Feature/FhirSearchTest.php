<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * F1: FHIR search parameters + pagination on the per-vault read surface.
 *
 * Honest scope: a registry-declared subset (token / reference / date / _id /
 * _lastUpdated) with FHIR comma-OR values and eq|ne|gt|lt|ge|le date prefixes,
 * lenient handling of unknown parameters, and _count/_offset paging with
 * self/next links. `total` is the total MATCH count, not the page size —
 * consumers rely on that distinction.
 */
final class FhirSearchTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @param array<string, mixed> $payload */
    private function commit(array $s, string $type, array $payload): string
    {
        $response = $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => $type,
            'payload' => ['resourceType' => $type] + $payload,
        ])->assertCreated();

        return $response->json('id');
    }

    public function test_id_parameter_selects_a_single_resource(): void
    {
        $s = $this->subjectWithVault();
        $keep = $this->commit($s, 'Condition', ['code' => ['text' => 'A'], 'subject' => ['reference' => 'Patient/x']]);
        $this->commit($s, 'Condition', ['code' => ['text' => 'B'], 'subject' => ['reference' => 'Patient/x']]);

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?_id={$keep}")
            ->assertOk();

        $this->assertSame(1, $bundle->json('total'));
        $this->assertSame($keep, $bundle->json('entry.0.resource.id'));
    }

    public function test_status_token_parameter_filters(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Observation', ['status' => 'final', 'code' => ['text' => 'BP']]);
        $this->commit($s, 'Observation', ['status' => 'preliminary', 'code' => ['text' => 'HR']]);

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?status=final")
            ->assertOk();

        $this->assertSame(1, $bundle->json('total'));
        $this->assertSame('final', $bundle->json('entry.0.resource.status'));
    }

    public function test_code_token_matches_system_pipe_code_and_bare_code(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Condition', [
            'subject' => ['reference' => 'Patient/x'],
            'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '38341003']], 'text' => 'Hypertension'],
        ]);
        $this->commit($s, 'Condition', [
            'subject' => ['reference' => 'Patient/x'],
            'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '44054006']], 'text' => 'Diabetes'],
        ]);

        $qualified = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?code=".urlencode('http://snomed.info/sct|38341003'))
            ->assertOk();
        $this->assertSame(1, $qualified->json('total'));

        $bare = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?code=44054006")
            ->assertOk();
        $this->assertSame(1, $bare->json('total'));

        $miss = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?code=99999")
            ->assertOk();
        $this->assertSame(0, $miss->json('total'));
    }

    public function test_comma_separated_token_values_are_or(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Observation', ['status' => 'final', 'code' => ['text' => 'A']]);
        $this->commit($s, 'Observation', ['status' => 'preliminary', 'code' => ['text' => 'B']]);
        $this->commit($s, 'Observation', ['status' => 'cancelled', 'code' => ['text' => 'C']]);

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?status=final,preliminary")
            ->assertOk();

        $this->assertSame(2, $bundle->json('total'));
    }

    public function test_date_parameter_supports_prefixes(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Observation', ['status' => 'final', 'code' => ['text' => 'old'], 'effectiveDateTime' => '2024-01-01T09:00:00Z']);
        $this->commit($s, 'Observation', ['status' => 'final', 'code' => ['text' => 'new'], 'effectiveDateTime' => '2025-06-15T09:00:00Z']);

        $ge = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?date=ge2025-01-01")
            ->assertOk();
        $this->assertSame(1, $ge->json('total'));
        $this->assertSame('new', $ge->json('entry.0.resource.code.text'));

        $le = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?date=le2024-12-31")
            ->assertOk();
        $this->assertSame(1, $le->json('total'));

        $eq = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?date=eq2025-06-15")
            ->assertOk();
        $this->assertSame(1, $eq->json('total'));
    }

    public function test_date_comparison_honors_timezone_offsets(): void
    {
        $s = $this->subjectWithVault();
        // 10:00 at -05:00 IS 15:00Z. A ge14:00Z filter must match it even
        // though the raw strings sort the other way.
        $this->commit($s, 'Observation', [
            'status' => 'final', 'code' => ['text' => 'offset'],
            'effectiveDateTime' => '2025-06-15T10:00:00-05:00',
        ]);

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Observation?date=".urlencode('ge2025-06-15T14:00:00Z'))
            ->assertOk();

        $this->assertSame(1, $bundle->json('total'));
    }

    public function test_patient_reference_parameter_matches_by_id_or_full_reference(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Condition', [
            'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            'code' => ['text' => 'mine'],
        ]);
        $this->commit($s, 'Condition', [
            'subject' => ['reference' => 'Patient/someone-else'],
            'code' => ['text' => 'other'],
        ]);

        $byId = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?patient={$s['vault_id']}")
            ->assertOk();
        $this->assertSame(1, $byId->json('total'));
        $this->assertSame('mine', $byId->json('entry.0.resource.code.text'));

        $byRef = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?patient=".urlencode("Patient/{$s['vault_id']}"))
            ->assertOk();
        $this->assertSame(1, $byRef->json('total'));
    }

    public function test_count_and_offset_paginate_with_next_links(): void
    {
        $s = $this->subjectWithVault();
        for ($i = 1; $i <= 5; $i++) {
            $this->commit($s, 'Condition', ['subject' => ['reference' => 'Patient/x'], 'code' => ['text' => "C{$i}"]]);
        }

        $page1 = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?_count=2")
            ->assertOk();

        $this->assertSame(5, $page1->json('total')); // total = matches, not page size
        $this->assertCount(2, $page1->json('entry'));

        $links = collect($page1->json('link'));
        $next = $links->firstWhere('relation', 'next');
        $this->assertNotNull($next);
        $this->assertStringContainsString('_offset=2', $next['url']);

        // Follow the next link (path+query against the test client).
        $parsed = parse_url($next['url']);
        $page2 = $this->withToken($s['token'])
            ->getJson($parsed['path'].'?'.$parsed['query'])
            ->assertOk();
        $this->assertCount(2, $page2->json('entry'));

        $page3Link = collect($page2->json('link'))->firstWhere('relation', 'next');
        $parsed3 = parse_url($page3Link['url']);
        $page3 = $this->withToken($s['token'])
            ->getJson($parsed3['path'].'?'.$parsed3['query'])
            ->assertOk();
        $this->assertCount(1, $page3->json('entry'));
        $this->assertNull(collect($page3->json('link'))->firstWhere('relation', 'next'));
    }

    public function test_last_updated_filters_on_commit_time(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Condition', ['subject' => ['reference' => 'Patient/x'], 'code' => ['text' => 'A']]);

        $all = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?_lastUpdated=ge2020-01-01")
            ->assertOk();
        $this->assertSame(1, $all->json('total'));

        $none = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?_lastUpdated=le2020-01-01")
            ->assertOk();
        $this->assertSame(0, $none->json('total'));
    }

    public function test_unknown_parameters_are_ignored_leniently(): void
    {
        $s = $this->subjectWithVault();
        $this->commit($s, 'Condition', ['subject' => ['reference' => 'Patient/x'], 'code' => ['text' => 'A']]);

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition?frobnicate=yes")
            ->assertOk();

        $this->assertSame(1, $bundle->json('total'));
    }

    public function test_capability_statement_declares_search_parameters(): void
    {
        $metadata = $this->getJson('/api/fhir/metadata')->assertOk();

        $resources = $metadata->json('rest.0.resource');
        $types = array_column($resources, 'type');
        $condition = $resources[array_search('Condition', $types, true)];

        $params = array_column($condition['searchParam'] ?? [], 'name');
        $this->assertContains('code', $params);
        $this->assertContains('patient', $params);
        $this->assertContains('_id', $params);
        $this->assertContains('_lastUpdated', $params);
    }
}
