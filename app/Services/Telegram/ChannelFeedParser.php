<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Data\FeedPage;
use App\Services\Telegram\Data\ParsedPost;
use App\Support\CountParser;
use App\Support\HtmlSanitizer;
use Carbon\CarbonImmutable;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Parses https://t.me/s/{username}: ~20 posts per page, older pages via ?before=.
 * Every selector Telegram uses lives in this one class (plan §7.2), so a markup
 * change is fixed here and caught by the fixture tests + the canary.
 */
final class ChannelFeedParser
{
    private const MEDIA_SELECTORS = [
        'photo' => '.tgme_widget_message_photo_wrap',
        'video' => '.tgme_widget_message_video_player, .tgme_widget_message_video',
        'roundvideo' => '.tgme_widget_message_roundvideo_player, .tgme_widget_message_roundvideo',
        'document' => '.tgme_widget_message_document',
        'poll' => '.tgme_widget_message_poll',
        'voice' => '.tgme_widget_message_voice',
        'audio' => '.tgme_widget_message_audio',
        'sticker' => '.tgme_widget_message_sticker, .tgme_widget_message_sticker_wrap',
        'gif' => '.tgme_widget_message_animation',
        'location' => '.tgme_widget_message_location',
    ];

    public function parse(string $username, string $html): FeedPage
    {
        $crawler = new Crawler($html);
        $posts = [];

        $crawler->filter('.tgme_widget_message[data-post]')->each(function (Crawler $node) use (&$posts, $username) {
            $post = $this->parseMessage($node, $username);

            if ($post !== null) {
                $posts[] = $post;
            }
        });

        return new FeedPage(
            username: strtolower($username),
            posts: $posts,
            olderCursor: $this->olderCursor($crawler, $posts),
            hasPreview: true,
            title: $this->text($crawler, '.tgme_channel_info_header_title'),
            description: $this->text($crawler, '.tgme_channel_info_description'),
            subscribers: $this->subscribers($crawler),
        );
    }

    private function parseMessage(Crawler $node, string $username): ?ParsedPost
    {
        $dataPost = (string) $node->attr('data-post');
        $msgId = (int) substr($dataPost, (int) strrpos($dataPost, '/') + 1);

        if ($msgId <= 0) {
            return null;
        }

        $classes = (string) $node->attr('class');
        $isService = str_contains($classes, 'service_message');

        $textNode = $node->filter('.tgme_widget_message_text')->reduce(
            // Skip the quoted text of the message being replied to.
            fn (Crawler $n) => ! $this->isInside($n, 'tgme_widget_message_reply'),
        );

        $rawHtml = $textNode->count() > 0 ? $textNode->last()->html('') : null;
        $text = $rawHtml !== null ? HtmlSanitizer::toText($rawHtml) : null;
        $text = $text === '' ? null : $text;

        $links = [];

        if ($textNode->count() > 0) {
            $textNode->last()->filter('a[href]')->each(function (Crawler $a) use (&$links) {
                $href = (string) $a->attr('href');

                if (preg_match('#^https?://#i', $href) === 1) {
                    $links[] = $href;
                }
            });
        }

        $linkPreview = $node->filter('a.tgme_widget_message_link_preview');

        if ($linkPreview->count() > 0 && preg_match('#^https?://#i', (string) $linkPreview->attr('href')) === 1) {
            $links[] = (string) $linkPreview->attr('href');
        }

        $forward = $node->filter('.tgme_widget_message_forwarded_from');
        $forwardedFrom = null;

        if ($forward->count() > 0) {
            $forwardLink = $forward->filter('a.tgme_widget_message_forwarded_from_name');
            $forwardedFrom = $forwardLink->count() > 0
                ? $this->forwardSource((string) $forwardLink->attr('href'), $forwardLink->text(''))
                : trim($forward->filter('.tgme_widget_message_forwarded_from_name')->count() > 0
                    ? $forward->filter('.tgme_widget_message_forwarded_from_name')->text('')
                    : $forward->text(''));
        }

        $reply = $node->filter('a.tgme_widget_message_reply');
        $replyTo = null;

        if ($reply->count() > 0 && preg_match('#/(\d+)(?:\?.*)?$#', (string) $reply->attr('href'), $m) === 1) {
            $replyTo = (int) $m[1];
        }

        $meta = $node->filter('.tgme_widget_message_meta');
        $edited = $meta->count() > 0 && str_contains(mb_strtolower($meta->text('')), 'edited');

        $time = $node->filter('.tgme_widget_message_date time[datetime], time[datetime]');
        $postedAt = null;

        if ($time->count() > 0) {
            try {
                $postedAt = CarbonImmutable::parse((string) $time->first()->attr('datetime'))->utc();
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $views = $node->filter('.tgme_widget_message_views');

        return new ParsedPost(
            msgId: $msgId,
            postedAt: $postedAt,
            edited: $edited,
            text: $text,
            textHtml: $rawHtml !== null ? HtmlSanitizer::clean($rawHtml) : null,
            views: $views->count() > 0 ? CountParser::parse($views->first()->text('')) : null,
            mediaTypes: $this->mediaTypes($node),
            isForward: $forward->count() > 0,
            forwardedFrom: $forwardedFrom !== '' ? $forwardedFrom : null,
            replyToMsgId: $replyTo,
            hashtags: $this->hashtags($text),
            links: array_values(array_unique($links)),
            reactions: $this->reactions($node),
            isService: $isService,
        );
    }

    /**
     * @return list<string>
     */
    private function mediaTypes(Crawler $node): array
    {
        $types = [];

        foreach (self::MEDIA_SELECTORS as $type => $selector) {
            $matches = $node->filter($selector)->reduce(
                fn (Crawler $n) => ! $this->isInside($n, 'tgme_widget_message_reply')
                    && ! $this->isInside($n, 'tgme_widget_message_link_preview'),
            );

            if ($matches->count() > 0) {
                $types[] = $type;
            }
        }

        if ($node->filter('.tgme_widget_message_grouped_wrap')->count() > 0) {
            $types[] = 'album';
        }

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>
     */
    private function hashtags(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        preg_match_all('/(?<![\w#])#([\p{L}\p{N}_]{2,64})/u', $text, $matches);

        return array_values(array_unique(array_map('mb_strtolower', $matches[1])));
    }

    /**
     * @return list<array{emoji: string, count: int}>
     */
    private function reactions(Crawler $node): array
    {
        $reactions = [];

        $node->filter('.tgme_widget_message_reactions .tgme_reaction')->each(function (Crawler $reaction) use (&$reactions) {
            $text = trim($reaction->text(''));

            if (preg_match('/([\d.,]+[KMB]?)\s*$/u', $text, $m) !== 1) {
                return;
            }

            $emoji = trim(mb_substr($text, 0, mb_strlen($text) - mb_strlen($m[0])));
            $isPaid = str_contains((string) $reaction->attr('class'), 'tgme_reaction_paid');

            $reactions[] = [
                'emoji' => $isPaid ? 'star' : ($emoji !== '' ? $emoji : 'custom'),
                'count' => (int) CountParser::parse($m[1]),
            ];
        });

        return $reactions;
    }

    /**
     * @param  list<ParsedPost>  $posts
     */
    private function olderCursor(Crawler $crawler, array $posts): ?int
    {
        $more = $crawler->filter('a.tme_messages_more[data-before]');

        if ($more->count() > 0) {
            return (int) $more->attr('data-before') ?: null;
        }

        $prev = $crawler->filter('link[rel="prev"]');

        if ($prev->count() > 0 && preg_match('/before=(\d+)/', (string) $prev->attr('href'), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function subscribers(Crawler $crawler): ?int
    {
        $count = null;

        $crawler->filter('.tgme_channel_info_counter')->each(function (Crawler $counter) use (&$count) {
            $type = $counter->filter('.counter_type');

            if ($type->count() > 0 && str_contains(mb_strtolower($type->text('')), 'subscriber')) {
                $count = CountParser::parse($counter->filter('.counter_value')->text(''));
            }
        });

        return $count;
    }

    private function forwardSource(string $href, string $name): string
    {
        if (preg_match('#^https?://t\.me/([A-Za-z0-9_]{4,32})#', $href, $m) === 1) {
            return strtolower($m[1]);
        }

        return trim($name);
    }

    private function isInside(Crawler $node, string $class): bool
    {
        $element = $node->getNode(0);

        while ($element !== null && ($element = $element->parentNode) instanceof DOMElement) {
            if (str_contains(' '.$element->getAttribute('class').' ', ' '.$class.' ')) {
                return true;
            }
        }

        return false;
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
}
