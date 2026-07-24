<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

use RuntimeException;

/**
 * File-backed SyncStateStore. All source state lives in one JSON file, written
 * atomically (write to a temp file in the same directory, then rename) so a
 * crash mid-write never corrupts the state file.
 */
final class JsonFileSyncStateStore implements SyncStateStore
{
    /** @var array<string, array{lastPulledAt: ?string, keys: array<string, array{versionId: string, vaultEntryId: string, contentFingerprint: string}>}> */
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = $this->load();
    }

    public function lastPulledAt(string $sourceId): ?string
    {
        return $this->data[$sourceId]['lastPulledAt'] ?? null;
    }

    public function markPulled(string $sourceId, string $pulledAt): void
    {
        $this->data[$sourceId] ??= ['lastPulledAt' => null, 'keys' => []];
        $this->data[$sourceId]['lastPulledAt'] = $pulledAt;
        $this->save();
    }

    public function get(string $sourceId, string $sourceKey): ?array
    {
        return $this->data[$sourceId]['keys'][$sourceKey] ?? null;
    }

    public function put(string $sourceId, string $sourceKey, string $versionId, string $vaultEntryId, string $contentFingerprint): void
    {
        $this->data[$sourceId] ??= ['lastPulledAt' => null, 'keys' => []];
        $this->data[$sourceId]['keys'][$sourceKey] = [
            'versionId' => $versionId,
            'vaultEntryId' => $vaultEntryId,
            'contentFingerprint' => $contentFingerprint,
        ];
        $this->save();
    }

    /** @return array<string, array{lastPulledAt: ?string, keys: array<string, array{versionId: string, vaultEntryId: string, contentFingerprint: string}>}> */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $content = @file_get_contents($this->path);
        if ($content === false || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        $tmp = $dir.'/.'.basename($this->path).'.'.bin2hex(random_bytes(4)).'.tmp';

        $written = @file_put_contents($tmp, json_encode($this->data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        if ($written === false) {
            throw new RuntimeException("cannot write {$tmp}");
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("cannot rename {$tmp} to {$this->path}");
        }
    }
}
