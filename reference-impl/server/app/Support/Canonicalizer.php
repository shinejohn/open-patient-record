<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic serialization for hashing (spec §4.2). Two semantically identical
 * payloads MUST hash identically regardless of key order, so object keys are sorted
 * recursively before encoding. List (sequential) arrays keep their order — order is
 * meaningful in FHIR lists.
 */
final class Canonicalizer
{
    /** @param array<mixed> $payload */
    public static function canonicalize(array $payload): string
    {
        return json_encode(
            self::sortKeys($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<mixed> $payload */
    public static function contentHash(array $payload): string
    {
        return hash('sha256', self::canonicalize($payload));
    }

    public static function chainHash(?string $previousChainHash, string $contentHash): string
    {
        return hash('sha256', ($previousChainHash ?? str_repeat('0', 64)).$contentHash);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $sorted = array_map(fn ($v) => self::sortKeys($v), $value);

        if (! $isList) {
            ksort($sorted);
        }

        return $sorted;
    }
}
