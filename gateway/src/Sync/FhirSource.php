<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

/**
 * Abstracts a FHIR-serving endpoint (the incumbent EHR) that the sync engine
 * pulls from. Implementations may be in-memory fixtures, a snapshot file, or —
 * at deployment time — a real HTTP FHIR client. The engine dedupes on content
 * regardless of whether $since is honored, so a naive full-pull implementation
 * is always correct, just less efficient.
 */
interface FhirSource
{
    /** @return list<string> FHIR resource type names this source can serve. */
    public function capabilities(): array;

    /**
     * @param  string|null  $since ISO8601 timestamp; implementations MAY ignore it and
     *                              return everything (the engine dedupes regardless).
     * @return list<array<string, mixed>> decoded FHIR resources of $type
     */
    public function fetch(string $type, ?string $since): array;
}
