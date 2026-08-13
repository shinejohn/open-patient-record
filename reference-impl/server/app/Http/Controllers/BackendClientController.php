<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\BackendClient;
use App\Models\Vault;
use App\Services\AuditLogger;
use App\Services\FhirResourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registration surface for SMART Backend Services clients (system-to-system).
 * Owner-only, like delegate management: deciding which unattended systems may
 * pull from this vault is the same category of authority as deciding who acts
 * for the patient. Only the client's PUBLIC key is ever accepted.
 */
final class BackendClientController
{
    use ResolvesGrantTokens;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request, Vault $vault): JsonResponse
    {
        $this->assertOwner($request, $vault);

        return response()->json([
            'backend_clients' => $vault->backendClients()->whereNull('revoked_at')->get()->map(fn (BackendClient $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'scope' => $c->scope,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request, Vault $vault): JsonResponse
    {
        $this->assertOwner($request, $vault);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'jwk' => ['required', 'array'],
            'jwk.kty' => ['required', 'in:RSA'],
            'jwk.n' => ['required', 'string'],
            'jwk.e' => ['required', 'string'],
            'jwks_uri' => ['nullable', 'url', 'max:2048'],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*' => ['string'],
        ]);

        foreach ($data['scope'] as $type) {
            if ($type !== '*' && ! FhirResourceRegistry::isSupported($type)) {
                return response()->json(['error' => 'unsupported_resource_type', 'type' => $type], 422);
            }
        }

        $client = BackendClient::query()->create([
            'vault_id' => $vault->id,
            'name' => $data['name'],
            'jwk' => $data['jwk'],
            'jwks_uri' => $data['jwks_uri'] ?? null,
            'scope' => array_values(array_unique($data['scope'])),
        ]);

        $this->audit->record($vault, 'backend_client.registered', actor: $request->user(), context: [
            'backend_client_id' => $client->id,
            'name' => $client->name,
            'scope' => $client->scope,
        ]);

        return response()->json(['id' => $client->id, 'name' => $client->name, 'scope' => $client->scope], 201);
    }

    public function revoke(Request $request, Vault $vault, BackendClient $client): JsonResponse
    {
        $this->assertOwner($request, $vault);

        if ($client->vault_id !== $vault->id) {
            abort(404);
        }

        if ($client->revoked_at === null) {
            $client->forceFill(['revoked_at' => now()])->save();
            $this->audit->record($vault, 'backend_client.revoked', actor: $request->user(), context: [
                'backend_client_id' => $client->id,
            ]);
        }

        return response()->json(['revoked' => true]);
    }
}
