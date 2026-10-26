<?php

namespace App\Services\Telegram\Data;

use Carbon\CarbonImmutable;

final readonly class ParsedPost
{
    /**
     * @param  list<string>  $mediaTypes
     * @param  list<string>  $hashtags
     * @param  list<string>  $links
     * @param  list<array{emoji: string, count: int}>  $reactions
     */
    public function __construct(
        public int $msgId,
        public ?CarbonImmutable $postedAt,
        public bool $edited,
        public ?string $text,
        public ?string $textHtml,
        public ?int $views,
        public array $mediaTypes,
        public bool $isForward,
        public ?string $forwardedFrom,
        public ?int $replyToMsgId,
        public array $hashtags,
        public array $links,
        public array $reactions,
        public bool $isService,
    ) {}

    public function hasMedia(): bool
    {
        return $this->mediaTypes !== [];
    }

    public function contentHash(): string
    {
        return hash('sha256', ($this->text ?? '').'|'.implode(',', $this->mediaTypes));
    }
}
