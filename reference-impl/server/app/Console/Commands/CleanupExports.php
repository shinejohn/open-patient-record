<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanupExports extends Command
{
    protected $signature = 'opr:cleanup-exports';

    protected $description = 'Delete expired bulk-export files and mark their jobs. Schedule hourly.';

    public function handle(): int
    {
        $expired = ExportJob::query()
            ->where('status', 'complete')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $job) {
            Storage::disk('local')->deleteDirectory($job->directory());
            $job->forceFill(['status' => 'cancelled', 'manifest' => null])->save();
        }

        $this->info("Cleaned {$expired->count()} expired export(s).");

        return self::SUCCESS;
    }
}
