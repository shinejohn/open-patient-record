<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Vault;

/**
 * Every vault access becomes an append-only audit event (spec §6).
 * Context carries identifiers only — never clinical values.
 */
final class AuditLogger
{
    /** @param array<string, mixed>|null $context */
    public function record(
        Vault $vault,
        string $action,
        ?User $actor = null,
        ?AccessGrant $grant = null,
        ?array $context = null,
        ?string $reason = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'vault_id' => $vault->id,
            'actor_user_id' => $actor?->id,
            'grant_id' => $grant?->id,
            'action' => $action,
            'purpose' => $grant?->purpose,
            'is_emergency' => (bool) ($grant?->is_emergency),
            'reason' => $reason ?? $grant?->emergency_reason,
            'context' => $context,
        ]);
    }
}
