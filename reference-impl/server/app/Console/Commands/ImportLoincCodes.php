<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TerminologyService;
use Illuminate\Console\Command;

/**
 * LOINC — free of charge but NON-REDISTRIBUTABLE: the operator accepts the
 * LOINC license at loinc.org, downloads the release, and points this command
 * at its Loinc.csv. Uses LOINC_NUM + LONG_COMMON_NAME. Nothing ships in-repo,
 * on purpose — bundling would violate the license this project asks its
 * operators to respect.
 */
final class ImportLoincCodes extends Command
{
    protected $signature = 'term:import-loinc {path : Loinc.csv from your licensed LOINC release download}';

    protected $description = 'Import LOINC codes from an operator-downloaded Loinc.csv';

    public function handle(TerminologyService $terminology): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $codeIdx = array_search('LOINC_NUM', $header, true);
        $nameIdx = array_search('LONG_COMMON_NAME', $header, true);
        if ($codeIdx === false || $nameIdx === false) {
            $this->error('Not a Loinc.csv: LOINC_NUM / LONG_COMMON_NAME columns missing.');
            fclose($handle);

            return self::FAILURE;
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $code = trim((string) ($line[$codeIdx] ?? ''));
            $display = trim((string) ($line[$nameIdx] ?? ''));
            if ($code !== '' && $display !== '') {
                $rows[] = ['code' => $code, 'display' => $display];
            }
        }
        fclose($handle);

        $count = $terminology->import('http://loinc.org', $rows);
        $this->info("Imported {$count} LOINC codes from {$path}.");

        return self::SUCCESS;
    }
}
