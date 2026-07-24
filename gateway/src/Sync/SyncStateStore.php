<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

/**
 * Persists per-source sync state: when the source was last pulled, and for
 * every sourceKey ("{resourceType}/{id}") we've seen, the versionId, the vault
 * entry id it was committed as, and the content fingerprint at that commit.
 */
interface SyncStateStore
{
    public function lastPulledAt(string $sourceId): ?string;

    public function markPulled(string $sourceId, string $pulledAt): void;

    /** @return array{versionId: string, vaultEntryId: string, contentFingerprint: string}|null */
    public function get(string $sourceId, string $sourceKey): ?array;

    public function put(string $sourceId, string $sourceKey, string $versionId, string $vaultEntryId, string $contentFingerprint): void;
}
