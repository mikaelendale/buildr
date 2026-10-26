<?php

namespace App\Services\Telegram\Data;

use App\Enums\TelegramPageType;

final readonly class ChannelInfo
{
    public function __construct(
        public string $username,
        public TelegramPageType $pageType,
        public ?string $title,
        public ?string $description,
        public ?string $avatarUrl,
        public ?int $subscribers,
        public bool $hasPreview,
    ) {}

    public function isChannel(): bool
    {
        return $this->pageType === TelegramPageType::Channel;
    }
}
