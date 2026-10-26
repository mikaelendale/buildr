<?php

namespace App\Models;

use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $channel_id
 * @property int|null $creator_id
 * @property int $version
 * @property SummaryMode $mode
 * @property array<array-key, mixed>|null $content
 * @property array<array-key, mixed>|null $citations
 * @property array<array-key, mixed>|null $verification
 * @property array<array-key, mixed>|null $month_digests
 * @property SummaryStatus $status
 * @property string|null $model
 * @property int $prompt_version
 * @property int $input_tokens
 * @property int $output_tokens
 * @property TriggeredBy $triggered_by
 * @property string|null $error
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Summary extends Model
{
    /**
     * Sections a summary can contain, in display order.
     */
    public const SECTIONS = [
        'hook',
        'vibe_tags',
        'intro',
        'building_now',
        'eras',
        'start_here',
        'in_their_words',
        'how_they_work',
        'fun_facts',
    ];

    /**
     * Sections that must survive verification for the summary to be usable.
     */
    public const REQUIRED_SECTIONS = ['hook', 'intro'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SummaryMode::class,
            'status' => SummaryStatus::class,
            'triggered_by' => TriggeredBy::class,
            'content' => 'array',
            'citations' => 'array',
            'verification' => 'array',
            'month_digests' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    public function section(string $key): mixed
    {
        return $this->content[$key] ?? null;
    }

    /**
     * Reasons the verifier gave for every dropped item.
     *
     * @return list<string>
     */
    public function droppedReasons(): array
    {
        $reasons = [];

        foreach ((array) ($this->verification['dropped'] ?? []) as $dropped) {
            if (is_array($dropped) && is_string($dropped['reason'] ?? null)) {
                $reasons[] = $dropped['reason'];
            }
        }

        return $reasons;
    }

    public function isPublishable(): bool
    {
        return in_array($this->status, [SummaryStatus::Verified, SummaryStatus::Approved], true);
    }
}
