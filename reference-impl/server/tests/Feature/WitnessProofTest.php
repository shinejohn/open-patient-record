<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\WitnessService;
use App\Support\Canonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Merkle inclusion proofs + witness key rotation (spec §4.5, key-mgmt runbook). */
final class WitnessProofTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_inclusion_proof_verifies_against_the_published_root(): void
    {
        // Three vaults so the Merkle tree has real structure (odd leaf count).
        $vaults = [];
        foreach (['a', 'b', 'c'] as $i) {
            $v = $this->subjectWithVault("{$i}@example.test");
            $this->commitEntry($v['token'], $v['vault_id'])->assertCreated();
            $vaults[] = $v;
        }
        $this->artisan('opr:publish-witness')->assertSuccessful();

        foreach ($vaults as $v) {
            $proof = $this->withToken($v['token'])
                ->getJson("/api/vaults/{$v['vault_id']}/witness-proof")
                ->assertOk()
                ->json();

            // Client-side verification: fold the path, land on the signed root.
            $this->assertTrue(WitnessService::verifyProof(
                $proof['chain_head_at_publish'],
                $proof['proof'],
                $proof['merkle_root'],
            ));

            // And the root's signature verifies from the public log alone.
            $this->assertTrue(sodium_crypto_sign_verify_detached(
                base64_decode($proof['signature'], true),
                Canonicalizer::canonicalize([
                    'published_at' => $proof['published_at'],
                    'merkle_root' => $proof['merkle_root'],
                    'vault_count' => 3,
                ]),
                base64_decode($proof['public_key'], true),
            ));
        }
    }

    public function test_a_vault_first_committed_after_the_digest_has_no_proof_yet(): void
    {
        $a = $this->subjectWithVault('early@example.test');
        $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        $b = $this->subjectWithVault('late@example.test');
        $this->commitEntry($b['token'], $b['vault_id'])->assertCreated();

        $this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}/witness-proof")
            ->assertNotFound();
    }

    public function test_key_rotation_publishes_a_dual_signed_rollover_and_continuity_holds(): void
    {
        $a = $this->subjectWithVault();
        $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        $before = $this->getJson('/api/witness-log')->json('entries');
        $oldKey = $before[0]['public_key'];

        $this->artisan('opr:rotate-witness-key')->assertSuccessful();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        $entries = $this->getJson('/api/witness-log')->assertOk()->json('entries');
        $this->assertCount(3, $entries); // digest, rollover, digest

        $rollover = $entries[1];
        $this->assertSame('key-rollover', $rollover['type']);
        $this->assertSame($oldKey, $rollover['old_public_key']);

        $statement = Canonicalizer::canonicalize([
            'new_public_key' => $rollover['new_public_key'],
            'published_at' => $rollover['published_at'],
            'type' => 'key-rollover',
        ]);
        // Signed by BOTH keys: the log itself proves signer continuity.
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($rollover['old_signature'], true), $statement,
            base64_decode($rollover['old_public_key'], true),
        ));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($rollover['new_signature'], true), $statement,
            base64_decode($rollover['new_public_key'], true),
        ));

        // Post-rotation digests are signed by the NEW key.
        $this->assertSame($rollover['new_public_key'], $entries[2]['public_key']);
        $this->assertNotSame($oldKey, $entries[2]['public_key']);
    }
}
