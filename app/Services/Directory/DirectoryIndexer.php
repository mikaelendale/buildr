<?php

namespace App\Services\Directory;

use App\Enums\ChannelAvailability;
use App\Enums\ChannelClaimStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\ProfileView;
use App\Models\Project;
use App\Services\Profiles\SummaryPresenter;
use App\Support\TelegramUsername;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps directory_entries + search_documents in sync (BuildSearchIndex, plan
 * §7.4). One entry per channel with a public page: published unclaimed
 * channels, and claimed channels of live creators that kept their page on.
 * A claimed channel is findable by its owner's name unless the owner chose
 * not to be shown on it.
 */
class DirectoryIndexer
{
    /**
     * Unavailable channels drop out of discover after a week (plan §7.6).
     */
    private const UNAVAILABLE_GRACE_DAYS = 7;

    public function __construct(private readonly SummaryPresenter $presenter) {}

    public function refreshChannel(Channel $channel): void
    {
        $channel->refresh()->load('creator.user', 'creator.tags');

        if (! $channel->hasPublicPage()) {
            $this->removeEntry($channel);

            return;
        }

        $attributes = $this->channelAttributes($channel);
        $documents = $this->channelDocuments($channel);
        $owner = $channel->showsOwner() ? $channel->creator : null;

        if ($owner !== null) {
            $documents = [
                ...$documents,
                ['display_name', $owner->display_name, 3],
                ['real_name', $owner->real_name, 3],
                ['real_name_am', $owner->real_name_am, 3],
                ['slug', $owner->slug, 2],
            ];

            foreach ($owner->tags as $tag) {
                $documents[] = ['tag', $tag->name, 1];
            }
        }

        $this->upsert($channel, $attributes, $documents);
    }

    /**
     * A creator is listed through their channels; their name, tags and
     * publish state feed every channel entry, so refresh them all.
     */
    public function refreshCreator(Creator $creator): void
    {
        $creator->refresh()->load('channels');

        $this->removeEntry($creator);

        $creator->channels
            ->where('claim_status', ChannelClaimStatus::Claimed)
            ->each(fn (Channel $channel) => $this->refreshChannel($channel));
    }

    public function removeEntry(Channel|Creator $listable): void
    {
        DirectoryEntry::where('listable_type', $listable->getMorphClass())
            ->where('listable_id', $listable->getKey())
            ->delete();
    }

    /**
     * Rebuild everything (used by `buildr:reindex` and after bulk changes).
     */
    public function rebuildAll(): int
    {
        $count = 0;

        // Creators used to have their own rows; their channels carry them now.
        DirectoryEntry::where('listable_type', (new Creator)->getMorphClass())->delete();

        Creator::published()->each(function (Creator $creator) use (&$count) {
            $this->refreshCreator($creator);
            $count += $creator->channels->where('claim_status', ChannelClaimStatus::Claimed)->count();
        });

        Channel::publishedUnclaimed()->each(function (Channel $channel) use (&$count) {
            $this->refreshChannel($channel);
            $count++;
        });

        // Stale rows whose source vanished.
        DirectoryEntry::query()->each(function (DirectoryEntry $entry) {
            if ($entry->listable === null) {
                $entry->delete();
            }
        });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function channelAttributes(Channel $channel): array
    {
        $channel->loadMissing('stats');
        $claimed = $channel->isClaimed() && $channel->creator !== null;
        $owner = $channel->showsOwner() ? $channel->creator : null;

        return [
            'url_path' => $channel->publicPath(),
            'creator_id' => $owner?->id,
            'name' => $channel->displayTitle(),
            'real_name' => null,
            'real_name_am' => null,
            'owner' => $owner === null ? null : [
                'name' => $owner->display_name,
                'url' => '/'.$owner->slug,
            ],
            'avatar_path' => $channel->avatar_path ?? $owner?->avatar_path,
            'hook' => $this->presenter->hook($channel),
            'vibe_tags' => $this->presenter->vibeTags($channel),
            'channels' => [$this->channelChip($channel)],
            'subscribers' => (int) ($channel->subscribers ?? 0),
            'is_claimed' => $claimed,
            'lang' => $this->primaryLang($channel),
            'last_post_at' => $channel->last_post_at,
            'posts_per_week' => (float) ($channel->stats?->get('posts_per_week') ?? 0),
            'trending_score' => $this->trendingScore($channel, collect([$channel])),
            'is_available' => $this->isAvailable($channel),
            'published_at' => $claimed ? ($channel->creator->published_at ?? now()) : ($channel->published_at ?? now()),
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: float|int}>
     */
    private function channelDocuments(Channel $channel): array
    {
        $documents = [
            ['channel_username', $channel->username, 4],
            ['channel_title', $channel->title, 2],
            ['slug', $channel->slug, 2],
        ];

        foreach ($channel->tags()->get() as $tag) {
            $documents[] = ['tag', $tag->name, 1];
        }

        foreach ($channel->projects()->visible()->get() as $project) {
            /** @var Project $project */
            $documents[] = ['project', $project->name, 1];
        }

        $presented = $this->presenter->present($channel)['content'];
        $summaryText = trim(implode(' ', array_filter([
            is_string($presented['hook'] ?? null) ? $presented['hook'] : null,
            is_string($presented['intro'] ?? null) ? $presented['intro'] : null,
            implode(' ', (array) ($presented['vibe_tags'] ?? [])),
        ])));

        if ($summaryText !== '') {
            $documents[] = ['summary', $summaryText, 0.5];
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: ?string, 2: float|int}>  $documents
     */
    private function upsert(Channel|Creator $listable, array $attributes, array $documents): void
    {
        DB::transaction(function () use ($listable, $attributes, $documents) {
            $entry = DirectoryEntry::updateOrCreate(
                ['listable_type' => $listable->getMorphClass(), 'listable_id' => $listable->getKey()],
                $attributes,
            );

            $entry->searchDocuments()->delete();

            $rows = collect($documents)
                ->filter(fn ($doc) => filled($doc[1]))
                ->map(fn ($doc) => [
                    'directory_entry_id' => $entry->id,
                    'field' => $doc[0],
                    'value' => self::normalize((string) $doc[1]),
                    'weight' => $doc[2],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->unique(fn ($row) => $row['field'].'|'.$row['value'])
                ->values()
                ->all();

            if ($rows !== []) {
                $entry->searchDocuments()->insert($rows);
            }
        });
    }

    /**
     * Lowercase, drop @/t.me prefixes, squeeze whitespace. Queries go through the same function.
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);

        if (TelegramUsername::looksLikeHandle($value)) {
            $value = TelegramUsername::normalize($value) ?? $value;
        }

        $value = preg_replace('#^(https?://)?(www\.)?#i', '', $value) ?? $value;

        return trim(mb_strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function channelChip(Channel $channel): array
    {
        return [
            'username' => $channel->username,
            'title' => $channel->displayTitle(),
            'subscribers' => $channel->subscribers,
        ];
    }

    private function primaryLang(?Channel $channel): ?string
    {
        $mix = (array) ($channel?->stats?->get('language_mix') ?? []);

        if ($mix === []) {
            return null;
        }

        arsort($mix);

        return (string) array_key_first($mix);
    }

    private function isAvailable(Channel $channel): bool
    {
        return $channel->availability === ChannelAvailability::Ok
            || $channel->unavailable_since === null
            || $channel->unavailable_since->gt(now()->subDays(self::UNAVAILABLE_GRACE_DAYS));
    }

    /**
     * Trending = profile views over the last 7 days + subscriber growth (plan §9.1).
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function trendingScore(Channel|Creator $listable, Collection $channels): float
    {
        $views = (int) ProfileView::where('viewable_type', $listable->getMorphClass())
            ->where('viewable_id', $listable->getKey())
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->sum('views');

        $growth = 0.0;

        foreach ($channels as $channel) {
            $gained = (int) ($channel->stats?->get('subscriber_growth_30d') ?? 0);
            $growth += max(0, $gained) / max(1, (int) $channel->subscribers);
        }

        return round(log(1 + $views) * 3 + $growth * 100, 4);
    }
}
