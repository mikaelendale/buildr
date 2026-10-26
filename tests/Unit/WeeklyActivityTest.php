<?php

use App\Services\Ai\ContentSelector;
use App\Services\Ai\StatsCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

it('buckets posts into weeks, oldest first, this week last', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00', 'Africa/Addis_Ababa'));
    $tz = 'Africa/Addis_Ababa';
    $now = CarbonImmutable::now($tz);

    $dates = new Collection([
        $now,                                   // this week
        $now->subDay(),                         // this week (Tuesday)
        $now->subWeek(),                        // last week
        $now->subWeeks(3)->startOfWeek(),       // three weeks ago, Monday 00:00
        $now->subWeeks(40),                     // outside the window
    ]);

    $weeks = (new StatsCalculator(new ContentSelector))->weeklyActivity($dates, $tz, 6);

    expect($weeks)->toBe([0, 0, 1, 0, 1, 2]);

    CarbonImmutable::setTestNow();
});
