<?php

declare(strict_types=1);

namespace Opr\Gateway;

/**
 * The output of parsing one document: candidates PLUS the completeness accounting
 * that makes silent drops structurally impossible (build plan §G0, docs/01 §3.4).
 * For every clinical domain the parser counts how many candidate *mentions* it
 * saw versus how many it actually extracted — so "14 medication references: 12
 * extracted, 2 unresolved" is data, not a hope.
 */
final class IngestionResult
{
    /** @var list<Candidate> */
    public array $candidates = [];

    /** @var array<string, array{found: int, extracted: int, unresolved: int}> */
    public array $mentionCounts = [];

    public string $classification = 'unknown';

    public string $sourceSha256 = '';

    public function add(Candidate $candidate): void
    {
        $this->candidates[] = $candidate;
        $this->bumpDomain($candidate->domain, extracted: true, unresolved: $candidate->route === Candidate::ROUTE_UNRESOLVED);
    }

    /** Record a mention the parser saw but could NOT extract into a candidate. */
    public function noteUnextractedMention(string $domain): void
    {
        $this->bumpDomain($domain, extracted: false, unresolved: true);
    }

    private function bumpDomain(string $domain, bool $extracted, bool $unresolved): void
    {
        $this->mentionCounts[$domain] ??= ['found' => 0, 'extracted' => 0, 'unresolved' => 0];
        $this->mentionCounts[$domain]['found']++;
        if ($extracted) {
            $this->mentionCounts[$domain]['extracted']++;
        }
        if ($unresolved) {
            $this->mentionCounts[$domain]['unresolved']++;
        }
    }

    public function unresolvedCount(): int
    {
        return array_sum(array_column($this->mentionCounts, 'unresolved'));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'classification' => $this->classification,
            'source_sha256' => $this->sourceSha256,
            'candidates' => count($this->candidates),
            'mention_counts' => $this->mentionCounts,
            'unresolved' => $this->unresolvedCount(),
        ];
    }
}
