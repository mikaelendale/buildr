<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\Setting;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * Daily spend cap (plan §8.9). When it is hit, AI jobs pause and the admin
 * gets one alert per day.
 */
class AiBudget
{
    public function __construct(private readonly TelegramNotifier $notifier) {}

    public function dailyLimit(): float
    {
        $override = Setting::read(Setting::AI_DAILY_BUDGET_USD);

        return is_numeric($override) ? (float) $override : (float) config('buildr.ai.daily_budget_usd');
    }

    public function spentToday(): float
    {
        return AiRun::spentToday();
    }

    public function isPaused(): bool
    {
        return Setting::flag(Setting::AI_PAUSED);
    }

    public function ensureAvailable(): void
    {
        if ($this->isPaused()) {
            throw new AiBudgetExceededException('AI jobs are paused by an admin.');
        }

        $spent = $this->spentToday();
        $limit = $this->dailyLimit();

        if ($spent < $limit) {
            return;
        }

        if (Cache::add('ai-budget-alert:'.now()->toDateString(), true, now()->endOfDay())) {
            $this->notifier->toAdmin(sprintf(
                'AI daily budget hit: $%.2f of $%.2f spent. AI jobs are paused until tomorrow (or raise the budget in /admin/ai-costs).',
                $spent,
                $limit,
            ));
        }

        throw new AiBudgetExceededException(sprintf('Daily AI budget of $%.2f reached.', $limit));
    }

    /**
     * Seconds until AI jobs may run again (start of the next day).
     */
    public function secondsUntilReset(): int
    {
        return max(60, (int) now()->diffInSeconds(now()->addDay()->startOfDay()));
    }
}
