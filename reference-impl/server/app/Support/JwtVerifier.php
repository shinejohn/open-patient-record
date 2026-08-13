<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal RS256 JWT verification against an arbitrary RSA public JWK — used ONLY
 * to verify SMART Backend Services client-assertion JWTs (foreign, untrusted
 * tokens signed by a registered backend client's own key). Distinct from
 * RsaKeyStore, which only ever SIGNS with OUR key.
 *
 * No composer dependency: RSA JWK -> PEM is a fixed, small piece of DER encoding
 * (RSAPublicKey wrapped in a SubjectPublicKeyInfo), and RS256 verification is a
 * single openssl_verify call once we have that PEM.
 */
final class JwtVerifier
{
    /**
     * Split a JWT into [header, payload, signingInput, signature] WITHOUT
     * verifying anything. Callers must verify before trusting any claim.
     *
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signingInput: string, signature: string}
     */
    public static function decodeUnsafe(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('malformed JWT');
        }
        [$h, $p, $s] = $parts;

        $header = json_decode(self::b64urlDecode($h), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_decode(self::b64urlDecode($p), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($header) || ! is_array($payload)) {
            throw new InvalidArgumentException('malformed JWT');
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'signingInput' => "{$h}.{$p}",
            'signature' => self::b64urlDecode($s),
        ];
    }

    /** @param array{n: string, e: string} $jwk */
    public static function verifyRs256(string $signingInput, string $signature, array $jwk): bool
    {
        $pem = self::jwkToPem($jwk);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** RSA JWK (base64url n, e) -> PEM-encoded SubjectPublicKeyInfo. */
    public static function jwkToPem(array $jwk): string
    {
        $n = self::b64urlDecode($jwk['n']);
        $e = self::b64urlDecode($jwk['e']);

        $rsaPublicKey = self::derSequence(self::derInteger($n).self::derInteger($e));

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL params.
        $algId = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"."\x05\x00"
        );
        $bitString = "\x03".self::derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $spki = self::derSequence($algId.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        // Strip leading zero bytes, then re-add exactly one if the high bit is set
        // (DER INTEGER is signed two's-complement; an unsigned value needs a
        // leading 0x00 whenever its top bit would otherwise read as negative).
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30".self::derLength(strlen($contents)).$contents;
    }

    private static function derLength(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function b64urlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('invalid base64url');
        }

        return $decoded;
    }
}
