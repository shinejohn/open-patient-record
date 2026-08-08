<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F4 remainder: document upload. The client filename is metadata only — it
 * is NEVER used as a storage path (that's a path-traversal / collision
 * hazard); the stored file always gets a hash-derived name.
 */
final class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string, patient_id: string} */
    private function ctx(): array
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 't', 'user' => ['id' => 'u1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'p1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'g1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
        ]);

        $p = $this->postJson('/api/register-practice', [
            'practice_name' => 'Riverbend', 'name' => 'Dr. O',
            'email' => 'o@r.test', 'password' => 'correct-horse-battery',
        ])->assertCreated();
        $pid = $this->withToken($p->json('token'))->postJson('/api/patients', ['name' => 'Ana'])
            ->assertCreated()->json('id');

        return ['token' => (string) $p->json('token'), 'patient_id' => $pid];
    }

    public function test_uploads_a_pdf_and_stores_it_under_a_hashed_name(): void
    {
        Storage::fake('local');
        $c = $this->ctx();

        $file = UploadedFile::fake()->create('super secret patient chart.pdf', 100, 'application/pdf');

        $res = $this->withToken($c['token'])
            ->post("/api/patients/{$c['patient_id']}/documents", ['file' => $file])
            ->assertCreated();

        $this->assertSame('super secret patient chart.pdf', $res->json('filename_original'));
        $storedPath = (string) $res->json('stored_path');
        $this->assertStringNotContainsString('super secret patient chart', $storedPath);
        Storage::disk('local')->assertExists($storedPath);
    }

    public function test_rejects_files_over_10mb(): void
    {
        Storage::fake('local');
        $c = $this->ctx();

        $file = UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf');

        $this->withToken($c['token'])
            ->post("/api/patients/{$c['patient_id']}/documents", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_rejects_disallowed_mime_types(): void
    {
        Storage::fake('local');
        $c = $this->ctx();

        $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

        $this->withToken($c['token'])
            ->post("/api/patients/{$c['patient_id']}/documents", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_lists_and_downloads_a_document(): void
    {
        Storage::fake('local');
        $c = $this->ctx();

        $file = UploadedFile::fake()->image('xray.png', 10, 10);
        $docId = $this->withToken($c['token'])
            ->post("/api/patients/{$c['patient_id']}/documents", ['file' => $file])
            ->assertCreated()->json('id');

        $list = $this->withToken($c['token'])
            ->getJson("/api/patients/{$c['patient_id']}/documents")->assertOk();
        $this->assertCount(1, $list->json('documents'));

        $download = $this->withToken($c['token'])->get("/api/documents/{$docId}/download")->assertOk();
        $this->assertSame('image/png', $download->headers->get('Content-Type'));
    }
}
