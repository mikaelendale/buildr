<?php

namespace App\Services\Creators;

use App\Enums\ChannelClaimStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Summary;
use App\Services\Profiles\ProfilePageBuilder;
use App\Services\Profiles\SummaryPresenter;

/**
 * Props for the section editor used in onboarding review (step 6) and
 * /dashboard/summary: the AI value, the live value, and override state.
 */
class EditorData
{
    public const LABELS = [
        'hook' => 'Hook line',
        'vibe_tags' => 'Vibe tags',
        'intro' => 'Intro',
        'building_now' => 'Building now',
        'eras' => 'The journey',
        'start_here' => 'Start here',
        'in_their_words' => 'In their own words',
        'how_they_work' => 'How they work',
        'fun_facts' => 'Fun facts',
    ];

    public function __construct(
        private readonly SummaryPresenter $presenter,
        private readonly ProfilePageBuilder $pages,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forCreator(Creator $creator): array
    {
        $editors = $creator->channels()
            ->where('claim_status', ChannelClaimStatus::Claimed)
            ->with(['overrides', 'stats'])
            ->get()
            ->sortBy(fn (Channel $c) => $c->id === $creator->primary_channel_id ? 0 : $c->id)
            ->map(fn (Channel $channel) => $this->forChannel($channel))
            ->values()
            ->all();

        return array_values($editors);
    }

    /**
     * @return array<string, mixed>
     */
    public function forChannel(Channel $channel, ?Summary $summary = null): array
    {
        $presented = $this->presenter->present($channel, $summary);
        $summary = $presented['summary'] ?? $channel->latestSummary()->first();
        $ai = $summary->content ?? [];
        $overrides = $channel->overrides->keyBy('section_key');

        return [
            'channel' => $this->pages->channelChip($channel, false),
            'channel_id' => $channel->id,
            'summary' => $summary === null ? null : [
                'id' => $summary->id,
                'version' => $summary->version,
                'status' => $summary->status->value,
                'mode' => $summary->mode->value,
                'updated_at' => $summary->updated_at?->toIso8601String(),
            ],
            'generating' => $channel->summaries()->where('status', 'generating')->exists(),
            'preview' => $this->pages->section($channel, $summary),
            'sections' => collect(self::LABELS)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'value' => $presented['content'][$key] ?? ($overrides->get($key)?->hidden ? ($ai[$key] ?? null) : null),
                'ai_value' => $ai[$key] ?? null,
                'overridden' => in_array($key, $presented['overridden'], true),
                'hidden' => in_array($key, $presented['hidden'], true),
                'stale' => in_array($key, $presented['stale'], true),
                'source' => $overrides->get($key)?->source?->value,
            ])->values()->all(),
            'projects' => $channel->projects()->orderBy('sort')->orderByDesc('mention_count')->get()->map(fn ($p) => $this->pages->project($p) + [
                'hidden' => $p->hidden,
                'source' => $p->source->value,
            ])->values()->all(),
        ];
    }
}
