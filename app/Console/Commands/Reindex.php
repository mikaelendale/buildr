<?php

namespace App\Console\Commands;

use App\Services\Directory\DirectoryIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:reindex')]
#[Description('Rebuild directory entries and search documents for every published profile')]
class Reindex extends Command
{
    public function handle(DirectoryIndexer $indexer): int
    {
        $count = $indexer->rebuildAll();

        $this->info("Reindexed {$count} listings.");

        return self::SUCCESS;
    }
}
