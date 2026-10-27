<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ChannelClaimStatus;
use App\Enums\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ProfileView;
use App\Services\Creators\OnboardingProgress;
use App\Services\Og\CardStyle;
use App\Services\Profiles\ProfilePageBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard: status banner, stats, quick actions (plan §4, §6.4).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ProfilePageBuilder $pages, OnboardingProgress $progress): Response|RedirectResponse
    {
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            return redirect('/onboarding');
        }

        $channels = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->with('stats')->get();

        // Profile + every channel page, one row per day.
        $views = ProfileView::ownedBy($creator)
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw('date, sum(views) as views, sum(unique_views) as unique_views')
            ->get();

        return Inertia::render('dashboard/index', [
            'creator' => [
                'id' => $creator->id,
                'name' => $creator->display_name,
                'slug' => $creator->slug,
                'url' => $creator->url(),
                'avatar' => $pages->avatar($creator->avatar_path ?? $creator->headerChannel()?->avatar_path),
                'status' => $creator->status->value,
                'review_note' => $creator->review_note,
                'pending_changes' => $creator->pending_changes,
                'onboarding_step' => $creator->onboarding_step,
                'published_at' => $creator->published_at?->toIso8601String(),
                'card' => CardStyle::for($creator)->toArray(),
            ],
            'channels' => $channels->map(fn ($c) => $pages->channelChip($c, $c->id === $creator->primary_channel_id) + [
                'last_scraped_at' => $c->last_scraped_at?->toIso8601String(),
                'posts_per_week' => $c->stats?->get('posts_per_week'),
                'scrape_status' => $c->scrape_status->value,
                'availability' => $c->availability->value,
            ])->values(),
            'pendingClaims' => Claim::where('user_id', $user->id)->where('status', ClaimStatus::Pending)->where('expires_at', '>', now())
                ->get()->map(fn ($c) => OnboardingController::claimPayloadFor($c))->values(),
            'views' => [
                'last7' => (int) $views->where('date', '>=', now()->subDays(6)->startOfDay())->sum('views'),
                'last30' => (int) $views->sum('views'),
                'unique30' => (int) $views->sum('unique_views'),
                'daily' => $views->map(fn ($v) => ['date' => $v->date->toDateString(), 'views' => (int) $v->views])->values(),
            ],
            'subscribers' => (int) $channels->sum('subscribers'),
            // Channels still being read/written (or just finished), with live stages.
            'building' => $progress->building($creator),
            'botCanMessage' => $user->bot_can_message,
            'telegram' => ['connected' => $user->hasTelegram(), 'username' => $user->telegram_username],
        ]);
    }
}
