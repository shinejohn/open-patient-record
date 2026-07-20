<?php

declare(strict_types=1);

namespace Opr\Gateway;

use RuntimeException;

/**
 * The human verification step, framed as medication reconciliation (build plan
 * §G0.4, docs/01 §3). A clinician reviews candidates and dispositions each one;
 * only after sign-off do accepted candidates become vault entries at
 * "clinician-verified". Two rules are load-bearing:
 *
 *   1. Completeness: sign-off is BLOCKED while any mention is unresolved, unless
 *      the reviewer explicitly acknowledges the unresolved set. Silent drops are
 *      structurally impossible.
 *   2. Batch-accept is permitted ONLY for deterministic-route candidates.
 */
final class Verification
{
    private bool $unresolvedAcknowledged = false;

    public function __construct(private readonly IngestionResult $result)
    {
    }

    public function accept(int $index): void
    {
        $this->candidate($index)->disposition = 'accepted';
    }

    /** @param array<string, mixed> $newPayload */
    public function edit(int $index, array $newPayload): void
    {
        $c = $this->candidate($index);
        $c->payload = $newPayload;
        $c->disposition = 'edited';
    }

    public function reject(int $index): void
    {
        $this->candidate($index)->disposition = 'rejected';
    }

    /** Batch-accept — deterministic candidates only. */
    public function acceptAllDeterministic(): int
    {
        $n = 0;
        foreach ($this->result->candidates as $c) {
            if ($c->disposition === 'pending' && $c->isDeterministic()) {
                $c->disposition = 'accepted';
                $n++;
            }
        }

        return $n;
    }

    public function acknowledgeUnresolved(): void
    {
        $this->unresolvedAcknowledged = true;
    }

    /**
     * Sign off: returns the accepted/edited candidates as commit-ready entries.
     * Throws if completeness is not satisfied — the reviewer must have dealt with
     * every candidate and acknowledged any unresolved mentions.
     *
     * @return list<array<string, mixed>>
     */
    public function signOff(string $verifierId, string $verifierName): array
    {
        $pending = array_filter($this->result->candidates, fn (Candidate $c) => $c->disposition === 'pending');
        if ($pending !== []) {
            throw new RuntimeException('Sign-off blocked: '.count($pending).' candidate(s) still pending review.');
        }
        if ($this->result->unresolvedCount() > 0 && ! $this->unresolvedAcknowledged) {
            throw new RuntimeException(
                'Sign-off blocked: '.$this->result->unresolvedCount().
                ' unresolved mention(s). Review them or call acknowledgeUnresolved().',
            );
        }

        $entries = [];
        foreach ($this->result->candidates as $c) {
            if (! in_array($c->disposition, ['accepted', 'edited'], true)) {
                continue;
            }
            $entries[] = [
                'resource_type' => $c->resourceType,
                'payload' => $c->payload,
                'verification_tier' => 'clinician-verified',
                'sensitive_category' => $c->sensitiveCategory,
                'provenance' => $c->provenance + [
                    'verifier_id' => $verifierId,
                    'verifier_name' => $verifierName,
                    'coding_source' => $c->codingSource,
                    'disposition' => $c->disposition,
                ],
            ];
        }

        return $entries;
    }

    private function candidate(int $index): Candidate
    {
        if (! isset($this->result->candidates[$index])) {
            throw new RuntimeException("no candidate at index {$index}");
        }

        return $this->result->candidates[$index];
    }
}
