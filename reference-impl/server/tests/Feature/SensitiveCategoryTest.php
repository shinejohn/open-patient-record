<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §3.2: sensitive categories are excluded unless explicitly granted; unknown = sensitive. */
final class SensitiveCategoryTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @return array{token: string, vault_id: string, grantFor: callable} */
    private function vaultWithMixedEntries(): array
    {
        $s = $this->subjectWithVault();

        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // plain Condition
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Opioid use disorder']],
            'sensitive_category' => '42_cfr_part_2',
        ])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Mystery condition']],
            'sensitive_category' => 'brand_new_category_nobody_recognizes',
        ])->assertCreated();

        return $s;
    }

    private function redeemedToken(array $s, array $grantOverrides = []): string
    {
        $mint = $this->mintGrant($s['token'], $s['vault_id'], $grantOverrides)->assertCreated();

        return $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk()->json('token');
    }

    public function test_sensitive_entries_are_excluded_from_grants_by_default(): void
    {
        $s = $this->vaultWithMixedEntries();
        $token = $this->redeemedToken($s);

        $entries = $this->withToken($token)
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries');

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['sensitive_category']);
    }

    public function test_explicitly_granted_category_is_visible(): void
    {
        $s = $this->vaultWithMixedEntries();
        $token = $this->redeemedToken($s, ['sensitive_categories' => ['42_cfr_part_2']]);

        $categories = collect($this->withToken($token)
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries'))->pluck('sensitive_category');

        $this->assertContains('42_cfr_part_2', $categories);
        // The unknown category stays hidden even though another category was granted.
        $this->assertNotContains('brand_new_category_nobody_recognizes', $categories);
    }

    public function test_unknown_category_never_means_shareable(): void
    {
        $s = $this->vaultWithMixedEntries();
        // Even a grant listing every KNOWN category does not expose the unknown one.
        $token = $this->redeemedToken($s, [
            'sensitive_categories' => ['42_cfr_part_2', 'mental_health', 'hiv', 'reproductive', 'genetic'],
        ]);

        $categories = collect($this->withToken($token)
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries'))->pluck('sensitive_category');

        $this->assertNotContains('brand_new_category_nobody_recognizes', $categories);
    }

    public function test_the_subject_always_sees_everything(): void
    {
        $s = $this->vaultWithMixedEntries();

        $entries = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries');

        $this->assertCount(3, $entries);
    }
}
