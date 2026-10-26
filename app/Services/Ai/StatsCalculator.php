<?php

namespace App\Services\Ai;

use App\Enums\PostLang;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\ChannelSnapshot;
use App\Models\ChannelStat;
use App\Models\Post;
use App\Models\PostExtraction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Step 3 (plan §8.4): pure code, in the channel's timezone. Stat cards render
 * straight from this JSON; the LLM may only reference its keys.
 */
class StatsCalculator
{
    private const EN_STOPWORDS = [
        'the', 'and', 'for', 'that', 'this', 'with', 'you', 'your', 'are', 'was', 'were', 'have', 'has', 'had',
        'not', 'but', 'from', 'they', 'them', 'their', 'what', 'when', 'where', 'which', 'who', 'will', 'would',
        'can', 'could', 'should', 'just', 'about', 'into', 'than', 'then', 'there', 'here', 'also', 'more', 'some',
        'all', 'any', 'one', 'our', 'out', 'get', 'got', 'its', 'it\'s', 'i\'m', 'don\'t', 'like', 'make', 'made',
        'now', 'new', 'how', 'why', 'very', 'really', 'been', 'being', 'over', 'only', 'other', 'because', 'after',
        'before', 'today', 'time', 'his', 'her', 'she', 'him', 'its', 'did', 'does', 'doing', 'let', 'lets', 'via',
        'http', 'https', 'www', 'com', 'org', 'amp',
    ];

    private const AM_STOPWORDS = [
        'እና', 'ነው', 'ላይ', 'ውስጥ', 'ወደ', 'ከ', 'ግን', 'ይህ', 'ያ', 'እኔ', 'እኛ', 'አንተ', 'እሱ', 'እሷ', 'እነሱ', 'ነበር',
        'ነገር', 'ብቻ', 'ደግሞ', 'ስለ', 'እንደ', 'ምን', 'ለምን', 'እንዴት', 'የት', 'መቼ', 'አለ', 'የለም', 'ናቸው', 'ይሆናል',
        'ሁሉ', 'ሁሉም', 'በጣም', 'አሁን', 'ዛሬ', 'ይህን', 'ይሄ', 'እዚህ', 'እዚያ', 'ጋር', 'ነኝ', 'ነህ', 'ነሽ', 'ናት',
    ];

    public function __construct(private readonly ContentSelector $selector) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(Channel $channel): array
    {
        $tz = $channel->effectiveTimezone();
        $posts = Post::where('channel_id', $channel->id)
            ->where('is_deleted', false)
            ->where('is_service', false)
            ->whereNotNull('posted_at')
            ->orderBy('posted_at')
            ->get();

        $textPosts = $posts->filter(fn (Post $p) => $this->selector->hasRealText((string) $p->text));
        $local = $posts->map(fn (Post $p) => $p->posted_at->setTimezone($tz));

        $hours = $local->map(fn (CarbonImmutable $d) => (int) $d->format('G'));
        $hourCounts = array_fill(0, 24, 0);

        foreach ($hours as $hour) {
            $hourCounts[$hour]++;
        }

        $total = max(1, $posts->count());
        $night = array_sum(array_slice($hourCounts, 0, 5)) / $total * 100;
        $early = array_sum(array_slice($hourCounts, 5, 3)) / $total * 100;
        $peakShare = max($hourCounts) / $total * 100;

        [$longest, $current] = $this->streaks($local);

        $extractions = PostExtraction::whereIn('post_id', $posts->pluck('id'))->get();
        $milestones = $extractions->whereNotNull('milestone')->count();
        $activeMonths = max(1, $local->isEmpty() ? 1 : (int) ceil($local->first()->diffInMonths($local->last()) + 1));

        $projects = $channel->projects()->visible()->get();

        $stats = [
            'active_since' => $local->first()?->format('Y-m'),
            'total_posts' => $posts->count(),
            'text_posts' => $textPosts->count(),
            'posts_per_week' => round($posts->where('posted_at', '>=', now()->subDays(90))->count() / (90 / 7), 1),
            'longest_streak_weeks' => $longest,
            'current_streak_weeks' => $current,
            'weekly_activity' => $this->weeklyActivity($local, $tz),
            'peak_hour' => $posts->isEmpty() ? null : array_search(max($hourCounts), $hourCounts, true),
            'night_owl_pct' => (int) round($night),
            'early_bird_pct' => (int) round($early),
            'posting_label' => match (true) {
                $posts->isEmpty() => null,
                $night >= 25 => 'Night Owl',
                $early >= 20 => 'Early Bird',
                $peakShare < 9 => 'All Hours',
                default => 'Daytime',
            },
            'avg_post_length' => (int) round($textPosts->avg(fn (Post $p) => mb_strlen((string) $p->text)) ?? 0),
            'language_mix' => $this->languageMix($textPosts),
            'top_post' => null,
            'top_5_posts' => [],
            'top_emoji' => $this->topEmoji($textPosts),
            'favorite_word' => $this->favoriteWord($textPosts),
            'ship_rate' => round($milestones / $activeMonths, 2),
            'milestones' => $milestones,
            'projects_count' => $projects->count(),
            'projects_shipped' => $projects->where('status', ProjectStatus::Launched)->count(),
            'forward_ratio' => (int) round($posts->where('is_forward', true)->count() / $total * 100),
            'subscriber_growth_30d' => $this->growth($channel),
        ];

        $top = $this->topPosts($channel, $textPosts);
        $stats['top_post'] = $top[0] ?? null;
        $stats['top_5_posts'] = $top;

        ChannelStat::updateOrCreate(['channel_id' => $channel->id], [
            'stats' => $stats,
            'computed_at' => now(),
        ]);

        return $stats;
    }

    /**
     * Posts per week for the last $weeks weeks (oldest first, this week last),
     * in the channel's timezone. Drives the activity chart on profiles.
     *
     * @param  Collection<int, CarbonImmutable>  $dates
     * @return list<int>
     */
    public function weeklyActivity(Collection $dates, string $tz, int $weeks = 26): array
    {
        $start = CarbonImmutable::now($tz)->startOfWeek()->subWeeks($weeks - 1);
        $counts = array_fill(0, $weeks, 0);

        foreach ($dates as $date) {
            $index = (int) round($start->diffInDays($date->startOfWeek()) / 7);

            if ($date->gte($start) && $index >= 0 && $index < $weeks) {
                $counts[$index]++;
            }
        }

        return array_values($counts);
    }

    /**
     * Consecutive ISO weeks with at least one post.
     *
     * @param  Collection<int, CarbonImmutable>  $dates
     * @return array{0: int, 1: int}
     */
    public function streaks(Collection $dates): array
    {
        if ($dates->isEmpty()) {
            return [0, 0];
        }

        $weeks = $dates->map(fn (CarbonImmutable $d) => $d->startOfWeek()->toDateString())->unique()->sort()->values();

        $longest = 1;
        $run = 1;

        for ($i = 1; $i < $weeks->count(); $i++) {
            $gap = CarbonImmutable::parse($weeks[$i - 1])->diffInWeeks(CarbonImmutable::parse($weeks[$i]));
            $run = (int) round($gap) === 1 ? $run + 1 : 1;
            $longest = max($longest, $run);
        }

        // The current streak counts back from this week (or last week, if this week has no post yet).
        $thisWeek = now($dates->first()->getTimezone())->startOfWeek();
        $lastPostWeek = CarbonImmutable::parse($weeks->last());
        $sinceLast = (int) round($lastPostWeek->diffInWeeks($thisWeek));

        $current = $sinceLast <= 1 ? $run : 0;

        return [$longest, $current];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<string, int>
     */
    private function languageMix(Collection $posts): array
    {
        $total = max(1, $posts->count());
        $mix = [];

        foreach (PostLang::cases() as $lang) {
            $mix[$lang->value] = (int) round($posts->where('lang', $lang)->count() / $total * 100);
        }

        return $mix;
    }

    /**
     * Engagement = views ÷ subscribers at the time (nearest earlier snapshot).
     *
     * @param  Collection<int, Post>  $posts
     * @return list<array{msg_id: int, views: int, ratio: float, posted_at: string|null}>
     */
    private function topPosts(Channel $channel, Collection $posts): array
    {
        $snapshots = ChannelSnapshot::where('channel_id', $channel->id)->orderBy('captured_at')->get();
        $fallback = max(1, (int) ($channel->subscribers ?? 1));

        return array_values($posts
            ->filter(fn (Post $p) => $p->views !== null && ! $p->is_forward && ! $p->is_ad)
            ->map(function (Post $post) use ($snapshots, $fallback) {
                $snapshot = $snapshots->last(fn (ChannelSnapshot $s) => $s->captured_at->lte($post->posted_at))
                    ?? $snapshots->first();
                $subscribers = max(1, (int) ($snapshot->subscribers ?? $fallback));

                return [
                    'msg_id' => $post->msg_id,
                    'views' => (int) $post->views,
                    'ratio' => round($post->views / $subscribers, 3),
                    'posted_at' => $post->posted_at?->toIso8601String(),
                ];
            })
            ->sortByDesc('ratio')
            ->take(5)
            ->all());
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function topEmoji(Collection $posts): ?string
    {
        $counts = [];

        foreach ($posts as $post) {
            preg_match_all('/\p{Extended_Pictographic}/u', (string) $post->text, $m);

            foreach ($m[0] as $emoji) {
                $counts[$emoji] = ($counts[$emoji] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function favoriteWord(Collection $posts): ?string
    {
        $stop = array_flip([...self::EN_STOPWORDS, ...self::AM_STOPWORDS]);
        $counts = [];

        foreach ($posts as $post) {
            $text = preg_replace('~https?://\S+|[@#]\w+~u', ' ', mb_strtolower((string) $post->text)) ?? '';
            preg_match_all('/[\p{L}][\p{L}\p{Mn}\']{2,}/u', $text, $m);

            foreach ($m[0] as $word) {
                if (! isset($stop[$word])) {
                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function growth(Channel $channel): ?int
    {
        $latest = ChannelSnapshot::where('channel_id', $channel->id)->latest('captured_at')->first();
        $old = ChannelSnapshot::where('channel_id', $channel->id)
            ->where('captured_at', '<=', now()->subDays(28))
            ->latest('captured_at')
            ->first();

        if ($latest === null || $old === null) {
            return null;
        }

        return $latest->subscribers - $old->subscribers;
    }
}
