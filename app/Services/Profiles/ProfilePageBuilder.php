<?php

namespace App\Services\Profiles;

use App\Enums\ChannelAvailability;
use App\Enums\ChannelClaimStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\CreatorSocial;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Models\Summary;
use App\Services\Media\AvatarStorer;
use App\Services\Og\OgImageRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the props for the public creator profile (§9.4: the person and the
 * channels they list) and the channel pages (§9.5: one per channel, claimed
 * or not). Also used for previews in onboarding and admin review.
 */
class ProfilePageBuilder
{
    public function __construct(private readonly SummaryPresenter $presenter) {}

    /**
     * The public profile passes $listedOnly: channels the owner hid, or
     * keeps without their name, stay off it (and out of its header). Admin
     * review sees every claimed channel with full sections.
     *
     * @return array<string, mixed>
     */
    public function creator(Creator $creator, bool $listedOnly = false): array
    {
        $creator->loadMissing(['channels.stats', 'channels.overrides', 'socials', 'tags', 'user']);

        $channels = $creator->channels
            ->where('claim_status', ChannelClaimStatus::Claimed)
            ->reject(fn (Channel $c) => $c->isRemoved())
            ->when($listedOnly, fn (Collection $all) => $all->filter(fn (Channel $c) => self::listed($c)))
            ->sortBy(fn (Channel $c) => $c->id === $creator->primary_channel_id ? 0 : $c->id)
            ->values();

        $header = $listedOnly ? $channels->first() : $creator->headerChannel();
        $headerContent = $header !== null ? $this->presenter->present($header)['content'] : [];
        $updated = $channels->map(fn (Channel $c) => $c->last_processed_at)->filter()->max();

        return [
            'type' => 'creator',
            'id' => $creator->id,
            'slug' => $creator->slug,
            'url' => $creator->url(),
            'name' => $creator->display_name,
            'real_name' => $creator->real_name,
            'real_name_am' => $creator->real_name_am,
            'location' => $creator->location,
            'bio' => $creator->bio,
            'avatar' => $this->avatar($creator->avatar_path ?? $header?->avatar_path),
            'verified' => true,
            'hook' => $headerContent['hook'] ?? null,
            'vibe_tags' => array_values((array) ($headerContent['vibe_tags'] ?? [])),
            'tags' => $creator->tags->map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name])->values()->all(),
            'socials' => $creator->socials->map(fn (CreatorSocial $s) => [
                'platform' => $s->platform->value,
                'label' => $s->platform->label(),
                'url' => $s->url,
                'handle' => $s->handle,
            ])->values()->all(),
            'channels' => $channels->map(fn (Channel $c) => $this->channelChip($c, $c->id === $header?->id))->values()->all(),
            'channel_cards' => $channels->map(fn (Channel $c) => $this->channelCard($c, $c->id === $header?->id))->values()->all(),
            'projects' => $this->projectsAcross($channels),
            'sections' => $listedOnly ? [] : $channels->map(fn (Channel $c) => $this->section($c))->values()->all(),
            'updated_at' => $updated?->toIso8601String(),
            'published_at' => $creator->published_at?->toIso8601String(),
            'og_image' => $this->ogImageUrl('creator', $creator->id),
        ];
    }

    /**
     * On the owner's public profile: page on, and listed under their name.
     */
    public static function listed(Channel $channel): bool
    {
        return $channel->page_visible && $channel->show_owner;
    }

    /**
     * @return array<string, mixed>
     */
    public function channel(Channel $channel): array
    {
        $channel->loadMissing(['stats', 'overrides', 'tags', 'creator.channels']);
        $content = $this->presenter->present($channel)['content'];
        $owner = $channel->showsOwner() ? $channel->creator : null;

        return [
            'type' => 'channel',
            'id' => $channel->id,
            'username' => $channel->username,
            'slug' => $channel->slug,
            'url' => $channel->publicUrl(),
            'claimed' => $channel->isClaimed(),
            'owner' => $owner === null ? null : [
                'name' => $owner->display_name,
                'slug' => $owner->slug,
                'url' => $owner->url(),
                'avatar' => $this->avatar($owner->avatar_path),
            ],
            'more_channels' => $owner === null ? [] : $owner->channels
                ->where('claim_status', ChannelClaimStatus::Claimed)
                ->reject(fn (Channel $c) => $c->id === $channel->id || $c->isRemoved() || ! self::listed($c))
                ->sortBy(fn (Channel $c) => $c->id === $owner->primary_channel_id ? 0 : $c->id)
                ->map(fn (Channel $c) => $this->channelChip($c, false))
                ->values()
                ->all(),
            'name' => $channel->displayTitle(),
            'description' => $channel->description,
            'avatar' => $this->avatar($channel->avatar_path),
            'subscribers' => $channel->subscribers,
            'hook' => $content['hook'] ?? null,
            'vibe_tags' => array_values((array) ($content['vibe_tags'] ?? [])),
            'available' => $channel->availability === ChannelAvailability::Ok,
            'availability' => $channel->availability->value,
            'channels' => [$this->channelChip($channel, true)],
            'sections' => [$this->section($channel)],
            'published_at' => $channel->published_at?->toIso8601String(),
            'og_image' => $this->ogImageUrl('channel', $channel->id),
        ];
    }

    /**
     * Per-channel content: stat cards, building now, journey, start here,
     * quotes, how they work and all projects.
     *
     * @return array<string, mixed>
     */
    public function section(Channel $channel, ?Summary $summary = null): array
    {
        $presented = $this->presenter->present($channel, $summary);
        $content = $presented['content'];
        $summary = $presented['summary'];
        $stats = $channel->stats->stats ?? [];

        $citedIds = collect(self::items($content, 'start_here'))->pluck('msg_id')
            ->merge(collect(self::items($content, 'in_their_words'))->pluck('msg_id'))
            ->filter()->unique()->values();

        // Posts deleted since the summary was written drop out of "Start here" and quotes (plan §16).
        $posts = $citedIds->isEmpty()
            ? collect()
            : Post::where('channel_id', $channel->id)->whereIn('msg_id', $citedIds)->where('is_deleted', false)->get()->keyBy('msg_id');

        $projects = $channel->projects()->visible()->orderBy('sort')->orderByDesc('mention_count')->get();
        $postUrl = fn (int $msgId) => $channel->telegramUrl($msgId);

        return [
            'channel' => $this->channelChip($channel, false),
            'has_summary' => $summary !== null,
            'basic' => $summary === null,
            'updated_at' => ($summary->updated_at ?? $channel->last_processed_at)?->toIso8601String(),
            'intro' => $content['intro'] ?? null,
            'stats' => $stats === [] ? null : [
                'active_since' => $stats['active_since'] ?? null,
                'posts_per_week' => $stats['posts_per_week'] ?? null,
                'longest_streak_weeks' => $stats['longest_streak_weeks'] ?? null,
                'current_streak_weeks' => $stats['current_streak_weeks'] ?? null,
                'posting_label' => $stats['posting_label'] ?? null,
                'peak_hour' => $stats['peak_hour'] ?? null,
                'language_mix' => $stats['language_mix'] ?? null,
                'projects_shipped' => $stats['projects_shipped'] ?? null,
                'projects_count' => $stats['projects_count'] ?? null,
                'total_posts' => $stats['total_posts'] ?? null,
                'top_emoji' => $stats['top_emoji'] ?? null,
                'weekly_activity' => is_array($stats['weekly_activity'] ?? null) ? array_values(array_map('intval', $stats['weekly_activity'])) : null,
            ],
            'building_now' => collect(self::items($content, 'building_now'))->map(function (array $item) use ($projects, $postUrl) {
                /** @var Project|null $project */
                $project = $projects->firstWhere('slug', $item['project_slug'] ?? null);

                return $project === null ? null : [
                    'project' => $this->project($project),
                    'line' => $item['line'] ?? null,
                    'sources' => collect(self::ids($item['cites'] ?? []))->map(fn (int $id) => ['msg_id' => $id, 'url' => $postUrl($id)])->all(),
                ];
            })->filter()->values()->all(),
            'eras' => collect(self::items($content, 'eras'))->map(fn (array $era) => [
                'name' => $era['name'] ?? '',
                'from' => $era['from'] ?? null,
                'to' => $era['to'] ?? null,
                'story' => $era['story'] ?? '',
                'sources' => collect(self::ids($era['cites'] ?? []))->map(fn (int $id) => ['msg_id' => $id, 'url' => $postUrl($id)])->all(),
            ])->values()->all(),
            'start_here' => collect(self::items($content, 'start_here'))
                ->filter(fn (array $item) => $posts->has((int) ($item['msg_id'] ?? 0)))
                ->map(function (array $item) use ($posts, $postUrl) {
                    /** @var Post|null $post */
                    $post = $posts->get((int) ($item['msg_id'] ?? 0));

                    return [
                        'msg_id' => (int) $item['msg_id'],
                        'url' => $postUrl((int) $item['msg_id']),
                        'why' => $item['why'] ?? '',
                        'snippet' => $post !== null ? Str::limit((string) $post->text, 160) : null,
                        'date' => $post?->posted_at?->toIso8601String(),
                        'views' => $post?->views,
                    ];
                })->values()->all(),
            'in_their_words' => collect(self::items($content, 'in_their_words'))
                ->filter(fn (array $item) => $posts->has((int) ($item['msg_id'] ?? 0)))
                ->map(function (array $item) use ($posts, $postUrl) {
                    return [
                        'msg_id' => (int) $item['msg_id'],
                        'url' => $postUrl((int) $item['msg_id']),
                        'quote' => $item['quote'] ?? '',
                        'date' => $posts->get((int) $item['msg_id'])?->posted_at?->toIso8601String(),
                    ];
                })->values()->all(),
            'how_they_work' => is_array($content['how_they_work'] ?? null) ? [
                'text' => $content['how_they_work']['text'] ?? '',
                'tools' => $this->topTools($channel),
            ] : null,
            'fun_facts' => array_values(array_filter(array_map(fn (array $f) => is_string($f['text'] ?? null) ? $f['text'] : null, self::items($content, 'fun_facts')))),
            'projects' => $projects->map(fn (Project $p) => $this->project($p))->values()->all(),
            'recent_posts' => $this->recentPosts($channel),
        ];
    }

    /**
     * The newest own posts, so a page has real content even before (or
     * between) AI summaries. Ads, forwards and posts flagged personal are out.
     *
     * @return list<array{msg_id: int, url: string, snippet: string, date: ?string, views: ?int}>
     */
    private function recentPosts(Channel $channel, int $limit = 6): array
    {
        return array_values(Post::where('channel_id', $channel->id)
            ->where('is_deleted', false)
            ->where('is_ad', false)
            ->where('is_forward', false)
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->whereDoesntHave('extraction', fn ($q) => $q->where('is_personal_sensitive', true))
            ->orderByDesc('msg_id')
            ->limit($limit)
            ->get()
            ->map(fn (Post $post) => [
                'msg_id' => $post->msg_id,
                'url' => $channel->telegramUrl($post->msg_id),
                'snippet' => Str::limit((string) $post->text, 240),
                'date' => $post->posted_at?->toIso8601String(),
                'views' => $post->views,
            ])
            ->all());
    }

    /**
     * List sections are stored as JSON (AI output or creator edits); keep only well-formed items.
     *
     * @param  array<array-key, mixed>  $content
     * @return list<array<array-key, mixed>>
     */
    public static function items(array $content, string $key): array
    {
        return array_values(array_filter((array) ($content[$key] ?? []), 'is_array'));
    }

    /**
     * @return list<int>
     */
    public static function ids(mixed $values): array
    {
        return array_values(array_filter(array_map('intval', array_filter((array) $values, 'is_numeric'))));
    }

    /**
     * @return array<string, mixed>
     */
    public function channelChip(Channel $channel, bool $primary): array
    {
        return [
            'username' => $channel->username,
            'title' => $channel->displayTitle(),
            'subscribers' => $channel->subscribers,
            'avatar' => $this->avatar(AvatarStorer::sized($channel->avatar_path, 128)),
            'url' => $channel->publicUrl(),
            'telegram_url' => $channel->telegramUrl(),
            'is_primary' => $primary,
            'available' => $channel->availability === ChannelAvailability::Ok,
        ];
    }

    /**
     * A channel on its owner's profile: identity, the AI hook and a few real
     * numbers, linking through to the channel's own page.
     *
     * @return array<string, mixed>
     */
    public function channelCard(Channel $channel, bool $primary): array
    {
        $content = $this->presenter->present($channel)['content'];
        $stats = $channel->stats->stats ?? [];

        return $this->channelChip($channel, $primary) + [
            'hook' => $content['hook'] ?? null,
            'vibe_tags' => array_values(array_slice((array) ($content['vibe_tags'] ?? []), 0, 3)),
            'description' => $channel->description,
            'posts_per_week' => is_numeric($stats['posts_per_week'] ?? null) ? (float) $stats['posts_per_week'] : null,
            'active_since' => $stats['active_since'] ?? null,
            'total_posts' => isset($stats['total_posts']) ? (int) $stats['total_posts'] : null,
            'projects_count' => $channel->projects()->visible()->count(),
            'last_post_at' => $channel->last_post_at?->toIso8601String(),
        ];
    }

    /**
     * Every visible project across the listed channels, each once (the
     * most-mentioned copy wins when two channels talk about the same thing).
     *
     * @param  Collection<int, Channel>  $channels
     * @return list<array<string, mixed>>
     */
    private function projectsAcross(Collection $channels): array
    {
        if ($channels->isEmpty()) {
            return [];
        }

        $byId = $channels->keyBy('id');

        return array_values(Project::whereIn('channel_id', $channels->pluck('id'))
            ->visible()
            ->orderByDesc('mention_count')
            ->get()
            ->unique(fn (Project $p) => Str::lower($p->normalized_name))
            ->sortBy(fn (Project $p) => [$p->sort, -$p->mention_count])
            ->take(12)
            ->map(function (Project $p) use ($byId) {
                $channel = $byId->get($p->channel_id);

                return $this->project($p) + [
                    'channel' => $channel instanceof Channel
                        ? ['username' => $channel->username, 'title' => $channel->displayTitle(), 'url' => $channel->publicUrl()]
                        : null,
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'url' => $project->url,
            'description' => $project->description,
            'history' => $project->history,
            'aliases' => $project->aliases ?? [],
            'status' => $project->status->value,
            'mention_count' => $project->mention_count,
            'first_seen' => $project->first_seen_at?->format('Y-m'),
            'last_seen' => $project->last_seen_at?->format('Y-m'),
        ];
    }

    /**
     * Most-mentioned tools from post extractions (stack chips).
     *
     * @return list<string>
     */
    private function topTools(Channel $channel): array
    {
        return array_values(PostExtraction::whereIn('post_id', Post::select('id')->where('channel_id', $channel->id))
            ->where('is_personal_sensitive', false)
            ->pluck('tools')
            ->flatten()
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->countBy(fn (string $t) => $t)
            ->sortDesc()
            ->keys()
            // PHP turns numeric-looking array keys into ints; tool names are strings.
            ->map(fn (int|string $tool) => (string) $tool)
            ->take(10)
            ->all());
    }

    public function avatar(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function ogImageUrl(string $type, int $id): string
    {
        $path = OgImageRenderer::path($type, $id);
        $version = Storage::disk('public')->exists($path) ? Storage::disk('public')->lastModified($path) : 0;

        return url("/og/{$type}/{$id}.png").($version ? "?v={$version}" : '');
    }

    /**
     * @param  Collection<int, Channel>  $channels
     */
    public static function totalSubscribers(Collection $channels): int
    {
        return (int) $channels->sum('subscribers');
    }
}
