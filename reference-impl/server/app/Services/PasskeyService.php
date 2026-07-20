<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Cose\Algorithms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys (WebAuthn) via web-auth/webauthn-lib — attestation/assertion parsing
 * is a security-critical format zoo we deliberately do NOT hand-roll.
 *
 * Reference-implementation posture: attestation 'none' accepted (consumer
 * passkeys don't attest), ES256/RS256, rpId = request host, localhost allowed
 * over http for development. Challenges are single-use rows in the database
 * (NEVER process-local cache — cross-process cache state fails silently).
 */
final class PasskeyService
{
    private SerializerInterface $serializer;

    private CeremonyStepManagerFactory $csmFactory;

    public function __construct()
    {
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());

        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        $this->csmFactory = new CeremonyStepManagerFactory();
        $this->csmFactory->setSecuredRelyingPartyId(['localhost']); // dev/tests only
        $this->csmFactory->setAttestationStatementSupportManager($attestationManager);
    }

    /** @return array{challenge_id: string, options: string} serialized JSON options */
    public function registrationOptions(User $user, string $rpId): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('OPR Vault Server', $rpId),
            user: PublicKeyCredentialUserEntity::create($user->email, $user->id, $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            ],
        );

        $json = $this->serializer->serialize($options, 'json');
        $challenge = $this->storeChallenge($user->id, 'register', $json);

        return ['challenge_id' => $challenge, 'options' => $json];
    }

    /** Verify a registration response; returns the stored credential row. */
    public function verifyRegistration(User $user, string $credentialJson, string $host, ?string $nickname): Model
    {
        $optionsJson = $this->consumeChallenge($user->id, 'register');
        $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');

        $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('not an attestation response');
        }

        $record = AuthenticatorAttestationResponseValidator::create($this->csmFactory->creationCeremony())
            ->check($response, $options, $host);

        return $user->credentials()->create([
            'credential_id' => rtrim(strtr(base64_encode($record->publicKeyCredentialId), '+/', '-_'), '='),
            'record' => $this->serializer->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'nickname' => $nickname,
        ]);
    }

    /** @return array{challenge_id: string, options: string} */
    public function loginOptions(?User $user, string $rpId): array
    {
        // No-oracle: unknown emails get a deterministic fake credential descriptor,
        // so the response shape never reveals whether an account exists.
        $descriptors = $user !== null
            ? $user->credentials->map(fn ($c) => PublicKeyCredentialDescriptor::create(
                'public-key',
                self::b64urlDecode($c->credential_id),
            ))->all()
            : [PublicKeyCredentialDescriptor::create(
                'public-key',
                hash_hmac('sha256', 'phantom-credential', config('app.key'), true),
            )];

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $rpId,
            allowCredentials: $descriptors,
        );

        $json = $this->serializer->serialize($options, 'json');
        $challenge = $this->storeChallenge($user?->id, 'login', $json);

        return ['challenge_id' => $challenge, 'options' => $json];
    }

    /**
     * Verify an assertion; returns the authenticated user or null (fail-closed —
     * callers map null to one generic response). Counter regression → deny.
     */
    public function verifyLogin(User $user, string $credentialJson, string $host): ?User
    {
        try {
            $optionsJson = $this->consumeChallenge($user->id, 'login');
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');

            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;
            if (! $response instanceof AuthenticatorAssertionResponse) {
                return null;
            }

            $credentialId = rtrim(strtr(base64_encode($credential->rawId), '+/', '-_'), '=');
            $stored = $user->credentials()->where('credential_id', $credentialId)->first();
            if ($stored === null) {
                return null;
            }

            $record = $this->serializer->deserialize($stored->record, CredentialRecord::class, 'json');

            $updated = AuthenticatorAssertionResponseValidator::create($this->csmFactory->requestCeremony())
                ->check($record, $response, $options, $host, $user->id);

            $stored->forceFill([
                'record' => $this->serializer->serialize($updated, 'json'),
                'sign_count' => $updated->counter,
                'last_used_at' => now(),
            ])->save();

            return $user;
        } catch (\Throwable) {
            return null; // includes counter regression: deny, never guess
        }
    }

    private function storeChallenge(?string $userId, string $type, string $optionsJson): string
    {
        $id = (string) Str::uuid7();
        DB::table('webauthn_challenges')->insert([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'options' => $optionsJson,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function consumeChallenge(string $userId, string $type): string
    {
        $row = DB::table('webauthn_challenges')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            throw new \RuntimeException('no active challenge');
        }

        DB::table('webauthn_challenges')->where('id', $row->id)->delete(); // single-use

        return $row->options;
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
