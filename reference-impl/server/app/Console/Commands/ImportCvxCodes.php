<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TerminologyService;
use Illuminate\Console\Command;

/**
 * CVX (CDC vaccine codes) — public domain, so a seed SUBSET ships in-repo and
 * is the default source. For production, download the full current set from
 * the CDC (IIS: HL7 Standard Code Set CVX) as CSV (code,display) and pass its
 * path. The bundled file is ~20 common codes to make demos and tests honest,
 * not a substitute for the release.
 */
final class ImportCvxCodes extends Command
{
    protected $signature = 'term:import-cvx {path? : CSV (code,display); defaults to the bundled seed subset}';

    protected $description = 'Import CVX vaccine codes (CDC, public domain)';

    public function handle(TerminologyService $terminology): int
    {
        $path = $this->argument('path') ?? database_path('seed-data/cvx.csv');
        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");

            return self::FAILURE;
        }

        $rows = [];
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle); // code,display
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) >= 2 && $line[0] !== '') {
                $rows[] = ['code' => trim($line[0]), 'display' => trim($line[1])];
            }
        }
        fclose($handle);

        $count = $terminology->import('http://hl7.org/fhir/sid/cvx', $rows);
        $this->info("Imported {$count} CVX codes from {$path}.");

        return self::SUCCESS;
    }
}
