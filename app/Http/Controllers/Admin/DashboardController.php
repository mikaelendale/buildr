<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChannelReviewStatus;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\CreatorStatus;
use App\Enums\ReportStatus;
use App\Enums\SummaryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\ProfileView;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Summary;
use App\Models\User;
use App\Services\Ai\AiBudget;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin: counters + 30-day charts (plan §10.1).
 */
class DashboardController extends Controller
{
    public function __invoke(AiBudget $budget): Response
    {
        $since = now()->subDays(29)->startOfDay();
        $days = collect(range(29, 0))->map(fn ($d) => now()->subDays($d)->toDateString());

        $signups = User::where('role', UserRole::Creator)->where('created_at', '>=', $since)->get(['created_at'])
            ->countBy(fn ($u) => $u->created_at->toDateString());
        $published = Creator::whereNotNull('published_at')->where('published_at', '>=', $since)->get(['published_at'])
            ->countBy(fn ($c) => $c->published_at->toDateString());
        $views = ProfileView::where('date', '>=', $since->toDateString())->get(['date', 'views'])
            ->groupBy(fn ($v) => $v->date->toDateString())->map->sum('views');

        return Inertia::render('admin/index', [
            'counters' => [
                'pending_reviews' => Creator::where('status', CreatorStatus::PendingReview)->count()
                    + Channel::where('review_status', ChannelReviewStatus::Pending)->count()
                    + Creator::whereNotNull('pending_changes')->count(),
                'failed_verification' => Summary::where('status', SummaryStatus::FailedVerification)->distinct('channel_id')->count('channel_id'),
                'open_reports' => Report::where('status', ReportStatus::Open)->count(),
                'pending_disputes' => Claim::where('purpose', ClaimPurpose::Dispute)->where('status', ClaimStatus::Pending)->count()
                    + Claim::where('status', ClaimStatus::Disputed)->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'queued_jobs' => DB::table('jobs')->count(),
                'scraping_paused' => Setting::flag(Setting::SCRAPING_PAUSED),
                'ai_paused' => $budget->isPaused(),
                'ai_spent_today' => round($budget->spentToday(), 4),
                'ai_budget' => $budget->dailyLimit(),
                'published_profiles' => Creator::where('status', CreatorStatus::Published)->count(),
                'published_channels' => Channel::publishedUnclaimed()->count(),
            ],
            'canary' => Setting::read(Setting::CANARY_STATUS),
            'charts' => $days->map(fn (string $d) => [
                'date' => $d,
                'signups' => (int) ($signups[$d] ?? 0),
                'published' => (int) ($published[$d] ?? 0),
                'views' => (int) ($views[$d] ?? 0),
            ])->values(),
        ]);
    }
}
