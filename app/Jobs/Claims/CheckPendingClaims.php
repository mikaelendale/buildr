<?php

namespace App\Jobs\Claims;

use App\Models\Claim;
use App\Services\Claims\ClaimService;
use App\Services\Telegram\Exceptions\BlockedUsernameException;
use App\Services\Telegram\Exceptions\ScrapingPausedException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 5 minutes (plan §6.2 #5): re-check pending claims so the creator can
 * close the tab after pasting the code.
 */
class CheckPendingClaims implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('scrape');
    }

    public function handle(ClaimService $claims): void
    {
        Claim::active()
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(4)))
            ->orderBy('last_checked_at')
            ->limit(50)
            ->get()
            ->each(function (Claim $claim) use ($claims) {
                try {
                    $claims->check($claim);
                } catch (ScrapingPausedException) {
                    return false;
                } catch (BlockedUsernameException) {
                    // Removal claims for blocklisted names are already done.
                } catch (Throwable $e) {
                    Log::channel('buildr')->warning("Claim check failed for #{$claim->id}: {$e->getMessage()}");
                }

                return true;
            });
    }
}
