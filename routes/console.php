<?php

use App\Jobs\Claims\CheckPendingClaims;
use App\Jobs\Scrape\RunScraperCanary;
use App\Services\Claims\ClaimService;
use App\Services\Directory\DirectoryIndexer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedules (plan §7.5)
|--------------------------------------------------------------------------
*/

// Every 5 minutes: re-check pending claim codes.
Schedule::job(new CheckPendingClaims)->everyFiveMinutes()->withoutOverlapping();

// Hourly: parser canary on a stable public channel.
Schedule::job(new RunScraperCanary)->hourly();

// Daily: incremental scrape + subscriber snapshot for published/pending channels.
Schedule::command('buildr:dispatch-scrapes')->dailyAt('02:00')->withoutOverlapping();

// Weekly: refresh recent views (and regenerate summaries with enough new material).
Schedule::command('buildr:dispatch-scrapes --weekly')->weeklyOn(1, '03:00');

// Daily housekeeping: expire claims, prune raw HTML, rebuild trending + sitemap.
Schedule::call(fn () => app(ClaimService::class)->expireStale())->daily()->name('expire-claims');
Schedule::command('buildr:prune-snapshots')->dailyAt('04:00');
Schedule::call(function () {
    app(DirectoryIndexer::class)->rebuildAll();
    Cache::forget('sitemap.xml');
})->dailyAt('05:00')->name('refresh-directory');

// Weekly: "your profile was updated" digest.
Schedule::command('buildr:weekly-digest')->weeklyOn(5, '09:00');

// Queue health.
Schedule::command('queue:prune-failed --hours=720')->daily();
