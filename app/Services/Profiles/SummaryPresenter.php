<?php

namespace App\Services\Profiles;

use App\Models\Channel;
use App\Models\Summary;
use App\Models\SummaryOverride;
use Illuminate\Support\Collection;

/**
 * Final page content = the current AI summary with summary_overrides applied
 * per section, edited value or hidden (plan §8.8).
 */
class SummaryPresenter
{
    /**
     * @return array{
     *     summary: Summary|null,
     *     content: array<string, mixed>,
     *     overridden: list<string>,
     *     hidden: list<string>,
     *     stale: list<string>
     * }
     */
    public function present(Channel $channel, ?Summary $summary = null): array
    {
        $summary ??= $channel->relationLoaded('currentSummary') ? $channel->currentSummary : $channel->currentSummary()->first();
        /** @var Collection<int, SummaryOverride> $overrides */
        $overrides = $channel->relationLoaded('overrides') ? $channel->overrides : $channel->overrides()->get();

        $content = $summary->content ?? [];
        $overridden = [];
        $hidden = [];
        $stale = [];

        foreach ($overrides as $override) {
            $key = $override->section_key;

            if (! in_array($key, Summary::SECTIONS, true)) {
                continue;
            }

            if ($override->hidden) {
                $hidden[] = $key;
                unset($content[$key]);

                continue;
            }

            if ($override->value !== null) {
                $content[$key] = $override->value['v'] ?? $override->value;
                $overridden[] = $key;

                // The AI wrote a newer version of a section the creator edited.
                if ($summary !== null && $override->based_on_summary_id !== null && $override->based_on_summary_id !== $summary->id) {
                    $stale[] = $key;
                }
            }
        }

        return [
            'summary' => $summary,
            'content' => $content,
            'overridden' => $overridden,
            'hidden' => $hidden,
            'stale' => $stale,
        ];
    }

    public function hook(Channel $channel): ?string
    {
        $hook = $this->present($channel)['content']['hook'] ?? null;

        return is_string($hook) && $hook !== '' ? $hook : null;
    }

    /**
     * @return list<string>
     */
    public function vibeTags(Channel $channel): array
    {
        return array_values((array) ($this->present($channel)['content']['vibe_tags'] ?? []));
    }
}
