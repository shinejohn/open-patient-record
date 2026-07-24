<?php

declare(strict_types=1);

namespace Opr\Gateway\Sync;

/**
 * Result of SyncEngine::pull(): per-type completeness counts plus the full list
 * of classified items. sum(new+changed+unchanged+unresolved) per type always
 * equals the fetched count — nothing is ever silently dropped.
 */
final class SyncPlan
{
    /** @var array<string, array{fetched: int, new: int, changed: int, unchanged: int, unresolved: int}> */
    public array $counts = [];

    /** @var list<SyncItem> */
    public array $items = [];

    public function add(SyncItem $item): void
    {
        $this->items[] = $item;
        $this->counts[$item->resourceType] ??= [
            'fetched' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'unresolved' => 0,
        ];
        $this->counts[$item->resourceType]['fetched']++;
        $this->counts[$item->resourceType][$item->classification]++;
    }

    /** @return list<SyncItem> */
    public function itemsOf(string $classification): array
    {
        return array_values(array_filter($this->items, fn (SyncItem $i) => $i->classification === $classification));
    }

    public function totalFetched(): int
    {
        return array_sum(array_column($this->counts, 'fetched'));
    }
}
