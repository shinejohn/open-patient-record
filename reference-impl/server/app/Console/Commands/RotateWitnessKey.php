<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WitnessService;
use Illuminate\Console\Command;

final class RotateWitnessKey extends Command
{
    protected $signature = 'opr:rotate-witness-key';

    protected $description = 'Rotate the witness signing key, publishing a rollover statement signed by both old and new keys (key-management runbook).';

    public function handle(WitnessService $witness): int
    {
        $record = $witness->rotateKey();

        $this->info('Witness key rotated. Rollover statement published to the witness log.');
        $this->line('New public key: '.$record['new_public_key']);

        return self::SUCCESS;
    }
}
