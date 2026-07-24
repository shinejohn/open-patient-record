<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TermCode;

/**
 * F2: local terminology resolution. The four supported systems and their
 * licensing posture (encoded as behavior, not just documentation):
 *
 *   CVX        — CDC, public domain. A seed subset ships in-repo.
 *   ICD-10-CM  — CMS, public. Operator imports the official order file.
 *   LOINC      — free but non-redistributable. Operator imports Loinc.csv from
 *                their own licensed download. Nothing ships in-repo.
 *   RxNorm     — operator imports RXNCONSO.RRF from the NLM Prescribable
 *                Content release. Nothing ships in-repo.
 *
 * SNOMED CT deliberately has no importer here: it requires a UMLS/affiliate
 * license per jurisdiction and shipping an importer without the licensing
 * context invites misuse. Codes already present on incoming resources are
 * carried as-is (the Gateway validates their SHAPE); server-side SNOMED
 * resolution is an operator decision for a licensed deployment.
 *
 * A KNOWN system with nothing imported answers honestly (not-found / false).
 * An UNKNOWN system is not-supported. Unknown never means "assume fine".
 */
final class TerminologyService
{
    public const SYSTEMS = [
        'http://hl7.org/fhir/sid/cvx',
        'http://hl7.org/fhir/sid/icd-10-cm',
        'http://loinc.org',
        'http://www.nlm.nih.gov/research/umls/rxnorm',
    ];

    public function isKnownSystem(string $system): bool
    {
        return in_array($system, self::SYSTEMS, true);
    }

    public function display(string $system, string $code): ?string
    {
        return TermCode::query()
            ->where('system', $system)
            ->where('code', $code)
            ->value('display');
    }

    /**
     * Idempotent bulk upsert.
     *
     * @param list<array{code: string, display: string}> $rows
     */
    public function import(string $system, array $rows): int
    {
        $count = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $records = [];
            $now = now();
            foreach ($chunk as $row) {
                $records[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'system' => $system,
                    'code' => $row['code'],
                    'display' => $row['display'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $count += count($records);
            TermCode::query()->upsert($records, ['system', 'code'], ['display', 'updated_at']);
        }

        return $count;
    }
}
