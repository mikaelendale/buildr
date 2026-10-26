<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Creator;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Og\OgImageRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:reindex {--cards : Also re-render every share card (after a design or APP_URL change)}')]
#[Description('Rebuild directory entries and search documents for every published profile')]
class Reindex extends Command
{
    public function handle(DirectoryIndexer $indexer, OgImageRenderer $og): int
    {
        $count = $indexer->rebuildAll();

        $this->info("Reindexed {$count} listings.");

        if ($this->option('cards')) {
            $cards = 0;

            Channel::query()->with('creator')->each(function (Channel $channel) use ($og, &$cards) {
                if ($channel->hasPublicPage() && $og->renderForChannel($channel) !== null) {
                    $cards++;
                }
            });

            Creator::query()->published()->each(function (Creator $creator) use ($og, &$cards) {
                if ($og->renderForCreator($creator) !== null) {
                    $cards++;
                }
            });

            $this->info("Rendered {$cards} share cards.");
        }

        return self::SUCCESS;
    }
}
