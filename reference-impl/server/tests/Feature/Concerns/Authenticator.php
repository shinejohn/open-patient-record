<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * A minimal software FIDO2 authenticator for tests: real EC P-256 keys, real CBOR,
 * real signatures — attestation format 'none', rpId 'localhost'. This exists so
 * passkey tests exercise the genuine WebAuthn verification path, not mocks.
 */
final class Authenticator
{
    public string $credentialId;

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    private string $x;

    private string $y;

    public function __construct()
    {
        $this->credentialId = random_bytes(32);

        $params = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = @openssl_pkey_new($params);
        if ($key === false) {
            // Some PHP builds (notably macOS/Homebrew) need an explicit openssl.cnf.
            foreach ([
                '/opt/homebrew/etc/openssl@3/openssl.cnf',
                '/usr/local/etc/openssl@3/openssl.cnf',
                '/etc/ssl/openssl.cnf',
            ] as $cnf) {
                if (is_file($cnf) && ($key = @openssl_pkey_new($params + ['config' => $cnf])) !== false) {
                    break;
                }
            }
        }
        if ($key === false) {
            throw new \RuntimeException('EC P-256 key generation unavailable in this PHP build.');
        }
        $this->key = $key;
        $details = openssl_pkey_get_details($this->key);
        $this->x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** @return array<string, mixed> a navigator.credentials.create() result */
    public function createAttestation(string $challengeB64url, string $origin = 'http://localhost'): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challengeB64url,
            'origin' => $origin,
        ], JSON_UNESCAPED_SLASHES);

        $coseKey = (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))      // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))     // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))     // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y));

        $authData = hash('sha256', 'localhost', true)
            .chr(0x45)                                  // UP | UV | AT
            .pack('N', 0)                               // sign count
            .str_repeat("\0", 16)                       // aaguid
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$coseKey;

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'attestationObject' => self::b64url($attestationObject),
            ],
        ];
    }

    /** @return array<string, mixed> a navigator.credentials.get() result */
    public function createAssertion(
        string $challengeB64url,
        int $counter,
        string $userHandle,
        string $origin = 'http://localhost',
    ): array {
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challengeB64url,
            'origin' => $origin,
        ], JSON_UNESCAPED_SLASHES);

        $authData = hash('sha256', 'localhost', true)
            .chr(0x05)                                  // UP | UV
            .pack('N', $counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
                'userHandle' => self::b64url($userHandle),
            ],
        ];
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
