<?php

namespace App\Services\Creators;

use App\Enums\ContentSource;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Jobs\Ai\RegenerateSection;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Summary;
use App\Models\SummaryOverride;
use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-section edit / hide / regenerate (plan §6.3 step 6, §8.8). Edits live
 * in summary_overrides keyed by channel, so regeneration never wipes them.
 */
class SummaryEditor
{
    public const EDITABLE = ['hook', 'vibe_tags', 'intro', 'building_now', 'eras', 'start_here', 'in_their_words', 'how_they_work', 'fun_facts'];

    public function __construct(private readonly DirectoryIndexer $indexer) {}

    public function edit(Channel $channel, string $section, mixed $value, User $editor, ContentSource $source = ContentSource::Creator): SummaryOverride
    {
        $this->ensureSection($section);

        $override = SummaryOverride::updateOrCreate(
            ['channel_id' => $channel->id, 'section_key' => $section],
            [
                'value' => ['v' => $this->clean($section, $value)],
                'hidden' => false,
                'source' => $source,
                'edited_by' => $editor->id,
                'based_on_summary_id' => $channel->currentSummary()->value('id'),
            ],
        );

        $this->after($channel, 'summary.section_edited', $section, $editor);

        return $override;
    }

    public function hide(Channel $channel, string $section, User $editor, bool $hidden = true, ContentSource $source = ContentSource::Creator): void
    {
        $this->ensureSection($section);

        $override = SummaryOverride::firstOrNew(['channel_id' => $channel->id, 'section_key' => $section]);

        if (! $hidden && $override->exists && $override->value === null) {
            $override->delete();
        } else {
            $override->forceFill([
                'hidden' => $hidden,
                'source' => $source,
                'edited_by' => $editor->id,
                'based_on_summary_id' => $override->based_on_summary_id ?? $channel->currentSummary()->value('id'),
            ])->save();
        }

        $this->after($channel, $hidden ? 'summary.section_hidden' : 'summary.section_shown', $section, $editor);
    }

    /**
     * Drop the override and go back to the AI version.
     */
    public function reset(Channel $channel, string $section, User $editor): void
    {
        SummaryOverride::where('channel_id', $channel->id)->where('section_key', $section)->delete();
        $this->after($channel, 'summary.section_reset', $section, $editor);
    }

    /**
     * "AI updated sections you edited": keep mine → re-base the override on the new summary.
     */
    public function keepMine(Channel $channel, string $section): void
    {
        SummaryOverride::where('channel_id', $channel->id)
            ->where('section_key', $section)
            ->update(['based_on_summary_id' => $channel->currentSummary()->value('id')]);
    }

    /**
     * Creator regenerations are limited per day (plan §6.3, regen.creator_daily_limit).
     */
    public function regenerationsLeftToday(User $user): int
    {
        $used = Summary::where('triggered_by', TriggeredBy::Creator)
            ->whereIn('channel_id', Channel::select('id')->where('creator_id', $user->creator?->id))
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, (int) config('buildr.regen.creator_daily_limit') - $used);
    }

    public function regenerate(Channel $channel, string $section, User $user): void
    {
        $this->ensureSection($section);

        if ($user->isAdmin() === false && $this->regenerationsLeftToday($user) <= 0) {
            throw ValidationException::withMessages(['section' => 'You have used all of today\'s regenerations. Try again tomorrow.']);
        }

        $current = $channel->currentSummary()->first() ?? $channel->latestSummary()->first();

        if ($current === null || $current->status === SummaryStatus::Generating) {
            throw ValidationException::withMessages(['section' => 'Your profile is still being written. Try again in a few minutes.']);
        }

        SummaryOverride::where('channel_id', $channel->id)->where('section_key', $section)->where('source', ContentSource::Creator)->delete();

        RegenerateSection::dispatch($channel->id, $current->id, $section, $user->isAdmin() ? 'admin' : 'creator', $user->id);
    }

    private function after(Channel $channel, string $action, string $section, User $editor): void
    {
        AuditLog::record($action, $channel, ['section' => $section], $editor);
        $this->indexer->refreshChannel($channel);
    }

    private function ensureSection(string $section): void
    {
        if (! in_array($section, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['section' => 'Unknown section.']);
        }
    }

    /**
     * Keep edited values in the same shape the page renders.
     */
    private function clean(string $section, mixed $value): mixed
    {
        $text = fn ($v, int $max) => Str::limit(trim(strip_tags((string) $v)), $max, '');

        return match ($section) {
            'hook' => $text($value, 200),
            'intro' => $text($value, 900),
            'vibe_tags' => collect(is_array($value) ? $value : explode(',', (string) $value))
                ->map(fn ($t) => $text($t, 24))->filter()->take(3)->values()->all(),
            'how_they_work' => ['text' => $text(is_array($value) ? ($value['text'] ?? '') : $value, 600), 'cites' => is_array($value) ? array_map('intval', (array) ($value['cites'] ?? [])) : []],
            'fun_facts' => collect((array) $value)->map(fn ($f) => ['text' => $text(is_array($f) ? ($f['text'] ?? '') : $f, 200), 'source' => is_array($f) ? (string) ($f['source'] ?? 'creator') : 'creator'])->filter(fn ($f) => $f['text'] !== '')->take(4)->values()->all(),
            'building_now' => collect((array) $value)->map(fn ($i) => ['project_slug' => (string) ($i['project_slug'] ?? ''), 'line' => $text($i['line'] ?? '', 300), 'cites' => array_map('intval', (array) ($i['cites'] ?? []))])->values()->all(),
            'eras' => collect((array) $value)->map(fn ($i) => ['name' => $text($i['name'] ?? '', 80), 'from' => (string) ($i['from'] ?? ''), 'to' => (string) ($i['to'] ?? ''), 'story' => $text($i['story'] ?? '', 600), 'cites' => array_map('intval', (array) ($i['cites'] ?? []))])->values()->all(),
            'start_here' => collect((array) $value)->map(fn ($i) => ['msg_id' => (int) ($i['msg_id'] ?? 0), 'why' => $text($i['why'] ?? '', 200)])->filter(fn ($i) => $i['msg_id'] > 0)->take(5)->values()->all(),
            'in_their_words' => collect((array) $value)->map(fn ($i) => ['msg_id' => (int) ($i['msg_id'] ?? 0), 'quote' => $text($i['quote'] ?? '', 300)])->filter(fn ($i) => $i['msg_id'] > 0)->take(2)->values()->all(),
            default => $value,
        };
    }
}
