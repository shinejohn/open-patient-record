<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TerminologyService;
use Illuminate\Console\Command;

/**
 * ICD-10-CM — public (CMS). Parses the official fixed-width order file
 * (icd10cm_order_YYYY.txt): order number [0:5], code [6:13], billable flag
 * [14], short description [16:76], long description [77:]. Codes longer than
 * three characters are stored in dotted display form (A000 → A00.0) because
 * that is how FHIR codings carry them; three-character category codes stay
 * undotted. Download from cms.gov — the file is public domain, but it is a
 * yearly release, so nothing ships in-repo to avoid a stale vintage
 * masquerading as current.
 */
final class ImportIcd10Codes extends Command
{
    protected $signature = 'term:import-icd10 {path : the CMS icd10cm_order_*.txt fixed-width file}';

    protected $description = 'Import ICD-10-CM codes from the official CMS order file';

    public function handle(TerminologyService $terminology): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read: {$path}");

            return self::FAILURE;
        }

        $rows = [];
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            if (strlen($line) < 77) {
                continue;
            }
            $code = trim(substr($line, 6, 7));
            $display = trim(substr($line, 77));
            if ($code === '' || $display === '') {
                continue;
            }
            if (strlen($code) > 3) {
                $code = substr($code, 0, 3).'.'.substr($code, 3);
            }
            $rows[] = ['code' => $code, 'display' => $display];
        }
        fclose($handle);

        $count = $terminology->import('http://hl7.org/fhir/sid/icd-10-cm', $rows);
        $this->info("Imported {$count} ICD-10-CM codes from {$path}.");

        return self::SUCCESS;
    }
}
