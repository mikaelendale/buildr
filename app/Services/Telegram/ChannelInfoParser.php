<?php

namespace App\Services\Telegram;

use App\Enums\TelegramPageType;
use App\Services\Telegram\Data\ChannelInfo;
use App\Support\CountParser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses https://t.me/{username}. The same URL renders channels, groups, bots,
 * users and "not found", so the page type is read from the markup.
 */
final class ChannelInfoParser
{
    public function parse(string $username, string $html): ChannelInfo
    {
        $crawler = new Crawler($html);

        $title = $this->text($crawler, '.tgme_page_title span[dir]') ?? $this->text($crawler, '.tgme_page_title');
        $title = $title !== null ? trim(str_replace('✔', '', $title)) : null;
        $extra = $this->text($crawler, '.tgme_page_extra');
        $description = $this->descriptionText($crawler);
        $avatar = $crawler->filter('img.tgme_page_photo_image')->count() > 0
            ? $crawler->filter('img.tgme_page_photo_image')->attr('src')
            : null;
        $action = mb_strtolower($this->text($crawler, '.tgme_page_action a') ?? '');
        $hasPreview = $crawler->filter('a.tgme_page_context_link[href^="/s/"]')->count() > 0;

        $type = $this->detectType($title, $extra, $action, $hasPreview);

        return new ChannelInfo(
            username: strtolower($username),
            pageType: $type,
            title: $type === TelegramPageType::NotFound ? null : $title,
            description: $type === TelegramPageType::NotFound ? null : $description,
            avatarUrl: $avatar !== null && $avatar !== '' ? $this->absolute($avatar) : null,
            subscribers: $type === TelegramPageType::Channel ? CountParser::parse($extra) : null,
            hasPreview: $hasPreview,
        );
    }

    private function detectType(?string $title, ?string $extra, string $action, bool $hasPreview): TelegramPageType
    {
        $extra = mb_strtolower($extra ?? '');

        return match (true) {
            $title === null || $title === '' => TelegramPageType::NotFound,
            str_contains($extra, 'subscriber') || $hasPreview => TelegramPageType::Channel,
            str_contains($extra, 'member') || str_contains($action, 'join group') => TelegramPageType::Group,
            str_contains($extra, 'monthly user') || str_contains($action, 'start bot') => TelegramPageType::Bot,
            default => TelegramPageType::User,
        };
    }

    private function text(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->first()->text('')) ?? '');

        return $text === '' ? null : $text;
    }

    private function descriptionText(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.tgme_page_description');

        if ($node->count() === 0) {
            return null;
        }

        $html = preg_replace('#<br\s*/?>#i', "\n", $node->first()->html('')) ?? '';
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, '//') ? 'https:'.$url : $url;
    }
}
