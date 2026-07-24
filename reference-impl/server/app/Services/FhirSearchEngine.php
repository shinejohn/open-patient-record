<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VaultEntry;

/**
 * F1: search-parameter evaluation for the per-vault FHIR surface.
 *
 * Payloads are stored encrypted as opaque FHIR JSON, so search is evaluated
 * in-memory over the decrypted current view — correct first, fast later; a
 * per-vault record is small (thousands of entries, not millions), which is the
 * honest reason this is acceptable in the reference implementation. Documented
 * here so nobody mistakes it for an indexed search service.
 *
 * Semantics implemented (and only these — the registry declares them):
 *   - token:      value `system|code` matches coding.system+code; bare value
 *                 matches coding.code, a plain string element, or an
 *                 identifier-less exact text match. Comma = OR (FHIR).
 *   - reference:  matches `X/id` exactly or by trailing id.
 *   - date:       eq|ne|gt|lt|ge|le prefixes, ISO string comparison; a
 *                 date-only value compares against the element's date part.
 *   - _id:        entry id equality.
 *   - _lastUpdated: date semantics against commit time.
 * Unknown parameters are ignored (FHIR lenient handling); they simply do not
 * constrain the result, and they are excluded from the self link.
 */
final class FhirSearchEngine
{
    /**
     * @param list<VaultEntry> $entries
     * @param array<string, mixed> $query
     * @return array{matches: list<VaultEntry>, applied: array<string, string>}
     */
    public function apply(array $entries, string $type, array $query): array
    {
        $applied = [];
        $matches = array_values($entries);

        foreach ($query as $param => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($param === '_id') {
                $applied[$param] = $value;
                $matches = array_values(array_filter(
                    $matches,
                    static fn (VaultEntry $e): bool => in_array($e->id, explode(',', $value), true),
                ));
                continue;
            }

            if ($param === '_lastUpdated') {
                $applied[$param] = $value;
                $matches = array_values(array_filter(
                    $matches,
                    fn (VaultEntry $e): bool => $this->dateMatches(
                        $e->created_at?->toIso8601String() ?? '',
                        $value,
                    ),
                ));
                continue;
            }

            $definition = FhirResourceRegistry::searchParam($type, $param);
            if ($definition === null) {
                continue; // lenient: unknown parameters never constrain
            }

            $applied[$param] = $value;
            $matches = array_values(array_filter(
                $matches,
                fn (VaultEntry $e): bool => $this->paramMatches($e->payload, $definition, $value),
            ));
        }

        return ['matches' => $matches, 'applied' => $applied];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{type: string, paths: list<string>} $definition
     */
    private function paramMatches(array $payload, array $definition, string $value): bool
    {
        foreach ($definition['paths'] as $path) {
            $element = $this->extract($payload, $path);
            if ($element === null) {
                continue;
            }

            $matched = match ($definition['type']) {
                'token' => $this->tokenMatches($element, $value),
                'reference' => $this->referenceMatches($element, $value),
                'date' => is_string($element) && $this->dateMatches($element, $value),
                default => false,
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /** Dot-path extraction over the payload array. */
    private function extract(array $payload, string $path): mixed
    {
        $current = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /** Comma = OR; each value is `system|code` or a bare code/string. */
    private function tokenMatches(mixed $element, string $value): bool
    {
        foreach (explode(',', $value) as $candidate) {
            $system = null;
            $code = $candidate;
            if (str_contains($candidate, '|')) {
                [$system, $code] = explode('|', $candidate, 2);
            }

            // Plain string element (e.g. Observation.status).
            if (is_string($element) && $system === null && $element === $code) {
                return true;
            }

            // CodeableConcept — match any coding.
            if (is_array($element)) {
                foreach ($element['coding'] ?? [] as $coding) {
                    if (($coding['code'] ?? null) === $code
                        && ($system === null || ($coding['system'] ?? null) === $system)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function referenceMatches(mixed $element, string $value): bool
    {
        $reference = is_array($element) ? ($element['reference'] ?? null) : null;
        if (! is_string($reference)) {
            return false;
        }

        foreach (explode(',', $value) as $candidate) {
            if ($reference === $candidate || str_ends_with($reference, '/'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ISO-string comparison: FHIR instants sort lexicographically, so prefix
     * comparison is exact without timezone arithmetic. A date-only search value
     * compares against the element's date part.
     */
    private function dateMatches(string $element, string $value): bool
    {
        $prefix = 'eq';
        if (preg_match('/\A(eq|ne|gt|lt|ge|le)(.+)\z/', $value, $m) === 1) {
            $prefix = $m[1];
            $value = $m[2];
        }

        $subject = strlen($value) === 10 ? substr($element, 0, 10) : $element;

        return match ($prefix) {
            'eq' => $subject === $value,
            'ne' => $subject !== $value,
            'gt' => $subject > $value,
            'lt' => $subject < $value,
            'ge' => $subject >= $value,
            'le' => $subject <= $value,
            default => false,
        };
    }
}
