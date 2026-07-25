<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The vault answered with a 4xx — a deliberate refusal, not an outage. Carries
 * enough context for callers to translate honestly (409 for a duplicate
 * registration, re-redemption for an expired derived token) instead of
 * collapsing every failure into "vault unreachable".
 */
final class VaultRejected extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }
}
