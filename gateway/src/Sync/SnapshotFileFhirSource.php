<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

use RuntimeException;

/**
 * Reads a FHIR Bundle JSON file and serves its entries as a FhirSource. This is
 * the dependency-free "source" the CLI can use TODAY: point it at a snapshot
 * export from the incumbent EHR. A live HTTP FhirSource (polling the incumbent's
 * FHIR API) is the deployment-time implementation of the same interface and can
 * be swapped in without touching SyncEngine.
 */
final class SnapshotFileFhirSource implements FhirSource
{
    private ArrayFhirSource $inner;

    public function __construct(string $bundlePath)
    {
        $content = @file_get_contents($bundlePath);
        if ($content === false) {
            throw new RuntimeException("cannot read {$bundlePath}");
        }

        $bundle = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $resources = [];
        foreach ($bundle['entry'] ?? [] as $entry) {
            $resource = $entry['resource'] ?? null;
            if (is_array($resource)) {
                $resources[] = $resource;
            }
        }

        $this->inner = new ArrayFhirSource($resources);
    }

    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function fetch(string $type, ?string $since): array
    {
        return $this->inner->fetch($type, $since);
    }
}
