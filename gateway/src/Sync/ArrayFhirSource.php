<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

/**
 * In-memory FhirSource for tests and fixtures. Constructed from a flat list of
 * decoded FHIR resources; honors $since by comparing each resource's
 * meta.lastUpdated when present (resources without meta.lastUpdated are always
 * returned — we never silently drop a resource just because we can't tell its age).
 */
final class ArrayFhirSource implements FhirSource
{
    /** @param list<array<string, mixed>> $resources */
    public function __construct(private array $resources)
    {
    }

    public function capabilities(): array
    {
        $types = [];
        foreach ($this->resources as $resource) {
            $type = $resource['resourceType'] ?? null;
            if (is_string($type) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public function fetch(string $type, ?string $since): array
    {
        $matches = [];
        foreach ($this->resources as $resource) {
            if (($resource['resourceType'] ?? null) !== $type) {
                continue;
            }
            if ($since !== null && $this->isBefore($resource, $since)) {
                continue;
            }
            $matches[] = $resource;
        }

        return $matches;
    }

    /** @param array<string, mixed> $resource */
    private function isBefore(array $resource, string $since): bool
    {
        $lastUpdated = $resource['meta']['lastUpdated'] ?? null;
        if (! is_string($lastUpdated)) {
            return false; // unknown age — never silently exclude
        }

        return strtotime($lastUpdated) < strtotime($since);
    }
}
