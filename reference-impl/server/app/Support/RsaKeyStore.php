<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * RSA-2048 signing key for OIDC id_tokens (RS256). We only ever SIGN with this
 * key — verification of foreign tokens is out of scope — so hand-rolling the JWT
 * signing (openssl_sign + base64url) is safe; parsing untrusted JWTs would not be.
 */
final class RsaKeyStore
{
    private const KEY_PATH = 'oauth/id-token-rsa.key';

    /** @return \OpenSSLAsymmetricKey */
    public static function privateKey()
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::KEY_PATH)) {
            $params = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = @openssl_pkey_new($params);
            if ($key === false) {
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
                throw new RuntimeException('RSA key generation unavailable.');
            }
            openssl_pkey_export($key, $pem);
            $disk->put(self::KEY_PATH, $pem);
        }

        $key = openssl_pkey_get_private($disk->get(self::KEY_PATH));
        if ($key === false) {
            throw new RuntimeException('Corrupt id-token signing key.');
        }

        return $key;
    }

    /** @return array{kty: string, alg: string, use: string, kid: string, n: string, e: string} */
    public static function jwk(): array
    {
        $details = openssl_pkey_get_details(self::privateKey());

        return [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'opr-1',
            'n' => self::b64url($details['rsa']['n']),
            'e' => self::b64url($details['rsa']['e']),
        ];
    }

    /** @param array<string, mixed> $claims */
    public static function signJwt(array $claims): string
    {
        $segment = fn (array $part): string => self::b64url(json_encode($part, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signingInput = $segment(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'opr-1']).'.'.$segment($claims);

        openssl_sign($signingInput, $signature, self::privateKey(), OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.self::b64url($signature);
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
