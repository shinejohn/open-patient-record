<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TerminologyService;
use Illuminate\Console\Command;

/**
 * RxNorm — parses RXNCONSO.RRF from the NLM "RxNorm Prescribable Content"
 * release (freely downloadable; the full UMLS release needs a UMLS license —
 * either file has this shape). Pipe-delimited; fields used: RXCUI[0], SAB[11],
 * TTY[12], STR[14], SUPPRESS[16]. Only SAB=RXNORM, non-suppressed rows import;
 * one display per RXCUI, chosen by term-type priority (clinical drug names
 * beat ingredient names). Nothing ships in-repo.
 */
final class ImportRxnormCodes extends Command
{
    protected $signature = 'term:import-rxnorm {path : RXNCONSO.RRF from an NLM RxNorm release}';

    protected $description = 'Import RxNorm codes from an operator-downloaded RXNCONSO.RRF';

    /** Higher = preferred display for the RXCUI. */
    private const TTY_PRIORITY = ['SCD' => 6, 'SBD' => 5, 'GPCK' => 4, 'BPCK' => 3, 'PIN' => 2, 'IN' => 2, 'BN' => 1];

    public function handle(TerminologyService $terminology): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");

            return self::FAILURE;
        }

        /** @var array<string, array{display: string, priority: int}> $best */
        $best = [];
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $fields = explode('|', $line);
            if (count($fields) < 17) {
                continue;
            }
            [$rxcui, $sab, $tty, $str, $suppress] = [$fields[0], $fields[11], $fields[12], $fields[14], $fields[16]];
            if ($sab !== 'RXNORM' || $suppress === 'Y' || $rxcui === '' || $str === '') {
                continue;
            }

            $priority = self::TTY_PRIORITY[$tty] ?? 0;
            if (! isset($best[$rxcui]) || $priority > $best[$rxcui]['priority']) {
                $best[$rxcui] = ['display' => $str, 'priority' => $priority];
            }
        }
        fclose($handle);

        $rows = [];
        foreach ($best as $rxcui => $entry) {
            $rows[] = ['code' => (string) $rxcui, 'display' => $entry['display']];
        }

        $count = $terminology->import('http://www.nlm.nih.gov/research/umls/rxnorm', $rows);
        $this->info("Imported {$count} RxNorm concepts from {$path}.");

        return self::SUCCESS;
    }
}
