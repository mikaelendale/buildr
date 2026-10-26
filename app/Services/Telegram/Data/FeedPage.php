<?php

namespace App\Services\Telegram\Data;

final readonly class FeedPage
{
    /**
     * @param  list<ParsedPost>  $posts  newest last, as Telegram renders them
     */
    public function __construct(
        public string $username,
        public array $posts,
        public ?int $olderCursor,
        public bool $hasPreview = true,
        public ?string $title = null,
        public ?string $description = null,
        public ?int $subscribers = null,
    ) {}

    public static function noPreview(string $username): self
    {
        return new self($username, [], null, false);
    }

    public function isEmpty(): bool
    {
        return $this->posts === [];
    }

    public function minMsgId(): ?int
    {
        return $this->posts === [] ? null : min(array_map(fn (ParsedPost $p) => $p->msgId, $this->posts));
    }

    public function maxMsgId(): ?int
    {
        return $this->posts === [] ? null : max(array_map(fn (ParsedPost $p) => $p->msgId, $this->posts));
    }
}
