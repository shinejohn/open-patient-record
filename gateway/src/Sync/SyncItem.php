<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

/**
 * One resource classified during a pull.
 */
final class SyncItem
{
    public const NEW = 'new';
    public const CHANGED = 'changed';
    public const UNCHANGED = 'unchanged';
    public const UNRESOLVED = 'unresolved';

    /** @param array<string, mixed> $resource */
    public function __construct(
        public readonly string $resourceType,
        public readonly ?string $sourceKey,
        public readonly string $classification,
        public readonly array $resource,
        public readonly ?string $priorVaultEntryId = null,
        public readonly ?string $unresolvedReason = null,
    ) {
    }
}
