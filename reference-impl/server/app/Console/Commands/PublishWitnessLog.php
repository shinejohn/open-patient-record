<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WitnessService;
use Illuminate\Console\Command;

final class PublishWitnessLog extends Command
{
    protected $signature = 'opr:publish-witness';

    protected $description = 'Publish a signed Merkle digest of all vault chain heads to the witness log (spec §4.5). Schedule daily.';

    public function handle(WitnessService $witness): int
    {
        $record = $witness->publish();

        $this->info(sprintf(
            'Witness published: root=%s vaults=%d at=%s',
            $record['merkle_root'],
            $record['vault_count'],
            $record['published_at'],
        ));

        return self::SUCCESS;
    }
}
