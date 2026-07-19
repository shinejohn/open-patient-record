<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Per-vault envelope encryption (internal docs: key-management architecture).
 * Each vault has its own 32-byte data-encryption key (DEK); payloads are sealed
 * with XSalsa20-Poly1305 (libsodium secretbox). One leaked DEK exposes ONE vault,
 * never the database — and destroying a vault's wrapped DEK after portability
 * export crypto-shreds the local copy without touching append-only history.
 *
 * The DEK itself is stored wrapped by the application master key (Laravel Crypt /
 * APP_KEY) — the stand-in for a cloud KMS root; swap the wrap/unwrap calls to
 * integrate a real KMS.
 */
final class EnvelopeCrypto
{
    private const PREFIX = 'oprv1:';

    public static function generateKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }

    /** @param array<mixed> $payload */
    public static function encrypt(array $payload, string $dek): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $dek));
    }

    /** @return array<mixed> */
    public static function decrypt(string $stored, string $dek): array
    {
        if (! str_starts_with($stored, self::PREFIX)) {
            // Row predating encryption enablement — plain JSON. Read-compatible.
            return json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('OPR: malformed encrypted payload.');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $dek,
        );

        if ($plain === false) {
            throw new RuntimeException('OPR: payload decryption failed (wrong vault key?).');
        }

        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }
}
