<?php

declare(strict_types=1);

namespace Opr\Gateway\Tests;

use Opr\Gateway\Candidate;
use Opr\Gateway\Sync\ArrayFhirSource;
use Opr\Gateway\Sync\JsonFileSyncStateStore;
use Opr\Gateway\Sync\SyncEngine;
use Opr\Gateway\Sync\SyncItem;
use PHPUnit\Framework\TestCase;

final class SyncEngineTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = tempnam(sys_get_temp_dir(), 'opr-sync-state-').'.json';
        @unlink($this->stateFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    /** @return list<array<string, mixed>> */
    private function baseResources(): array
    {
        return [
            [
                'resourceType' => 'MedicationStatement',
                'id' => 'med-1',
                'meta' => ['versionId' => '1', 'lastUpdated' => '2026-01-01T00:00:00Z'],
                'status' => 'active',
                'medicationCodeableConcept' => ['text' => 'Lisinopril 10mg'],
            ],
            [
                'resourceType' => 'AllergyIntolerance',
                'id' => 'allergy-1',
                'meta' => ['versionId' => '1', 'lastUpdated' => '2026-01-01T00:00:00Z'],
                'code' => ['text' => 'Penicillin'],
            ],
            [
                'resourceType' => 'Condition',
                // no id
                'meta' => ['versionId' => '1'],
                'code' => ['text' => 'Hypertension'],
            ],
        ];
    }

    public function test_initial_pull_classifies_everything_correctly(): void
    {
        $source = new ArrayFhirSource($this->baseResources());
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $plan = $engine->pull(['MedicationStatement', 'AllergyIntolerance', 'Condition']);

        $this->assertSame(1, $plan->counts['MedicationStatement']['new']);
        $this->assertSame(1, $plan->counts['AllergyIntolerance']['new']);
        $this->assertSame(1, $plan->counts['Condition']['unresolved']);
        $this->assertCount(1, $plan->itemsOf(SyncItem::UNRESOLVED));
        $this->assertSame('resource has no id', $plan->itemsOf(SyncItem::UNRESOLVED)[0]->unresolvedReason);

        $candidates = $engine->toCandidates($plan);
        $this->assertCount(2, $candidates); // Condition unresolved -> not a candidate
        foreach ($candidates as $c) {
            $this->assertSame('fhir-sync', $c->provenance['extraction_method']);
            $this->assertSame('incumbent-ehr', $c->provenance['source_system']);
            $this->assertNull($c->replacesVaultEntryId);
        }
    }

    public function test_second_pull_with_no_content_change_is_all_unchanged(): void
    {
        $resources = $this->baseResources();
        $source = new ArrayFhirSource($resources);
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $first = $engine->pull(['MedicationStatement', 'AllergyIntolerance']);
        foreach ($first->itemsOf(SyncItem::NEW) as $item) {
            $engine->recordCommitted($item->sourceKey, '1', 'vault-entry-'.$item->sourceKey, SyncEngine::fingerprint($item->resource));
        }
        $engine->markPulled('2026-01-02T00:00:00Z');

        // Bump meta.lastUpdated only — content unchanged. Both resources are
        // touched so both remain visible to a $since-filtering source.
        $resources[0]['meta']['lastUpdated'] = '2026-01-03T00:00:00Z';
        $resources[1]['meta']['lastUpdated'] = '2026-01-03T00:00:00Z';
        $touchedSource = new ArrayFhirSource($resources);
        $engine2 = new SyncEngine($touchedSource, $state, 'incumbent-ehr');

        $second = $engine2->pull(['MedicationStatement', 'AllergyIntolerance']);

        $this->assertSame(2, $second->counts['MedicationStatement']['unchanged'] + $second->counts['AllergyIntolerance']['unchanged']);
        $this->assertCount(0, $engine2->toCandidates($second));
    }

    public function test_changed_content_produces_superseding_candidate(): void
    {
        $resources = $this->baseResources();
        $source = new ArrayFhirSource($resources);
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $first = $engine->pull(['MedicationStatement']);
        $medItem = $first->itemsOf(SyncItem::NEW)[0];
        $engine->recordCommitted($medItem->sourceKey, '1', 'vault-entry-med-1', SyncEngine::fingerprint($medItem->resource));
        $engine->markPulled('2026-01-02T00:00:00Z');

        $resources[0]['medicationCodeableConcept']['text'] = 'Lisinopril 20mg'; // real content change
        $resources[0]['meta']['versionId'] = '2';
        $resources[0]['meta']['lastUpdated'] = '2026-01-03T00:00:00Z';
        $engine2 = new SyncEngine(new ArrayFhirSource($resources), $state, 'incumbent-ehr');

        $second = $engine2->pull(['MedicationStatement']);
        $this->assertSame(1, $second->counts['MedicationStatement']['changed']);

        $candidates = $engine2->toCandidates($second);
        $this->assertCount(1, $candidates);
        $this->assertSame('vault-entry-med-1', $candidates[0]->replacesVaultEntryId);
    }

    public function test_resource_without_id_is_unresolved_and_counted_never_dropped(): void
    {
        $source = new ArrayFhirSource($this->baseResources());
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $plan = $engine->pull(['Condition']);

        $this->assertSame(1, $plan->counts['Condition']['fetched']);
        $this->assertSame(1, $plan->counts['Condition']['unresolved']);
    }

    public function test_record_committed_round_trip_makes_next_pull_unchanged(): void
    {
        $resources = $this->baseResources();
        $source = new ArrayFhirSource($resources);
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $plan = $engine->pull(['MedicationStatement']);
        $item = $plan->itemsOf(SyncItem::NEW)[0];
        $engine->recordCommitted($item->sourceKey, '1', 'vault-entry-med-1', SyncEngine::fingerprint($item->resource));

        $engine2 = new SyncEngine(new ArrayFhirSource($resources), $state, 'incumbent-ehr');
        $second = $engine2->pull(['MedicationStatement']);

        $this->assertSame(1, $second->counts['MedicationStatement']['unchanged']);
        $this->assertSame(0, $second->counts['MedicationStatement']['new']);
    }

    public function test_json_file_sync_state_store_persists_across_instantiation(): void
    {
        $state = new JsonFileSyncStateStore($this->stateFile);
        $state->put('src-a', 'MedicationStatement/med-1', 'v1', 'vault-1', 'fp-abc');
        $state->markPulled('src-a', '2026-01-01T00:00:00Z');

        $this->assertFileExists($this->stateFile);

        $reloaded = new JsonFileSyncStateStore($this->stateFile);
        $this->assertSame('2026-01-01T00:00:00Z', $reloaded->lastPulledAt('src-a'));
        $entry = $reloaded->get('src-a', 'MedicationStatement/med-1');
        $this->assertSame('vault-1', $entry['vaultEntryId']);
        $this->assertSame('fp-abc', $entry['contentFingerprint']);
    }

    public function test_completeness_counts_sum_to_fetched(): void
    {
        $source = new ArrayFhirSource($this->baseResources());
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $plan = $engine->pull(['MedicationStatement', 'AllergyIntolerance', 'Condition']);

        foreach ($plan->counts as $type => $counts) {
            $this->assertSame(
                $counts['fetched'],
                $counts['new'] + $counts['changed'] + $counts['unchanged'] + $counts['unresolved'],
                "type {$type} completeness mismatch",
            );
        }
        $this->assertSame(3, $plan->totalFetched());
    }

    public function test_candidate_provenance_carries_fingerprint_matching_raw_resource(): void
    {
        $resources = $this->baseResources();
        $source = new ArrayFhirSource($resources);
        $state = new JsonFileSyncStateStore($this->stateFile);
        $engine = new SyncEngine($source, $state, 'incumbent-ehr');

        $plan = $engine->pull(['MedicationStatement']);
        $item = $plan->itemsOf(SyncItem::NEW)[0];
        $candidates = $engine->toCandidates($plan);

        $this->assertSame(SyncEngine::fingerprint($item->resource), $candidates[0]->provenance['content_fingerprint']);
    }

    public function test_fingerprint_ignores_meta_but_detects_content_change(): void
    {
        $a = ['resourceType' => 'Condition', 'id' => 'c1', 'meta' => ['lastUpdated' => 't1'], 'code' => ['text' => 'X']];
        $b = ['resourceType' => 'Condition', 'id' => 'c1', 'meta' => ['lastUpdated' => 't2'], 'code' => ['text' => 'X']];
        $c = ['resourceType' => 'Condition', 'id' => 'c1', 'meta' => ['lastUpdated' => 't1'], 'code' => ['text' => 'Y']];

        $this->assertSame(SyncEngine::fingerprint($a), SyncEngine::fingerprint($b));
        $this->assertNotSame(SyncEngine::fingerprint($a), SyncEngine::fingerprint($c));
    }

    public function test_checkpoint_is_the_pull_start_time_not_completion_time(): void
    {
        // CRITICAL review finding: recording the checkpoint AFTER processing
        // permanently loses any resource the incumbent updates mid-run when the
        // source honors $since. The checkpoint must be the pull START.
        $clock = ['2026-01-01T00:00:00+00:00', '2026-01-01T00:10:00+00:00'];
        $i = 0;
        $engine = new SyncEngine(
            new ArrayFhirSource([]),
            $store = new JsonFileSyncStateStore($this->stateFile),
            'src-1',
            function () use (&$clock, &$i): string { return $clock[min($i++, count($clock) - 1)]; },
        );

        $engine->pull(['Condition']); // clock tick 1: 00:00 — the start
        $engine->markPulled();        // no arg: must use the captured START time

        $this->assertSame('2026-01-01T00:00:00+00:00', $store->lastPulledAt('src-1'));
    }

    public function test_sign_off_carries_replaces_entry_id_for_changed_resources(): void
    {
        $store = new JsonFileSyncStateStore($this->stateFile);
        $store->put('src-1', 'Condition/c1', '1', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'stale-fingerprint');

        $engine = new SyncEngine(new ArrayFhirSource([
            ['resourceType' => 'Condition', 'id' => 'c1', 'code' => ['text' => 'changed'], 'subject' => ['reference' => 'Patient/p']],
        ]), $store, 'src-1');

        $plan = $engine->pull(['Condition']);
        $candidates = $engine->toCandidates($plan);
        $this->assertCount(1, $candidates);

        $result = new \Opr\Gateway\IngestionResult();
        $result->classification = 'fhir-sync';
        foreach ($candidates as $c) { $result->add($c); }
        $verification = new \Opr\Gateway\Verification($result);
        $verification->acceptAllDeterministic();
        $entries = $verification->signOff('ver-1', 'Dr. Verifier');

        $this->assertSame(
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            $entries[0]['replaces_entry_id'] ?? null,
            'a CHANGED resource must supersede its prior vault entry at sign-off',
        );
    }
}
