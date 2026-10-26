<?php

namespace App\Console\Commands;

use App\Enums\CreatorStatus;
use App\Enums\TriggeredBy;
use App\Models\Creator;
use App\Models\ProfileView;
use App\Models\Summary;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * "Summary auto-updated" weekly digest (plan §13), opt-out in settings.
 */
#[Signature('buildr:weekly-digest')]
#[Description('DM creators whose profile the AI updated this week')]
class SendWeeklyDigest extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        $sent = 0;

        Creator::where('status', CreatorStatus::Published)->with(['user', 'channels'])->each(function (Creator $creator) use ($notifier, &$sent) {
            if ($creator->user === null || ! $creator->user->notify_summary_digest) {
                return;
            }

            $updated = Summary::whereIn('channel_id', $creator->channels->pluck('id'))
                ->where('triggered_by', TriggeredBy::System)
                ->where('created_at', '>=', now()->subWeek())
                ->exists();

            if (! $updated) {
                return;
            }

            $views = (int) ProfileView::ownedBy($creator)
                ->where('date', '>=', now()->subWeek()->toDateString())->sum('views');

            if ($notifier->toUser($creator->user, "📬 Your Buildr profile picked up your latest posts this week ({$views} profile views). Review what changed: ".url('/dashboard/summary'))) {
                $sent++;
            }
        });

        $this->info("Sent {$sent} digests.");

        return self::SUCCESS;
    }
}
