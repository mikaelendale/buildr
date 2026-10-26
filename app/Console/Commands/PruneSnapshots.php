<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('buildr:prune-snapshots')]
#[Description('Delete raw t.me HTML snapshots older than the retention window (30 days)')]
class PruneSnapshots extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk((string) config('buildr.scrape.snapshot_disk'));
        $cutoff = now()->subDays((int) config('buildr.scrape.snapshot_retention_days'))->format('Y-m-d');
        $deleted = 0;

        foreach ($disk->directories('snapshots') as $directory) {
            if (basename($directory) < $cutoff) {
                $disk->deleteDirectory($directory);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} snapshot day(s).");

        return self::SUCCESS;
    }
}
