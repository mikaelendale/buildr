<?php

use App\Services\Telegram\ChannelFeedParser;
use App\Services\Telegram\Data\ParsedPost;

function syntheticFeed(): array
{
    $page = (new ChannelFeedParser)->parse('testbuilder', fixtureContents('telegram/feed-synthetic.html'));

    return collect($page->posts)->keyBy(fn (ParsedPost $p) => $p->msgId)->all();
}

it('parses a real channel feed page', function (string $username) {
    $page = (new ChannelFeedParser)->parse($username, fixtureContents("telegram/feed-{$username}.html"));

    expect($page->posts)->toHaveCount(20)
        ->and($page->olderCursor)->toBeInt()->toBe($page->minMsgId())
        ->and($page->subscribers)->toBeGreaterThan(1_000_000)
        ->and(collect($page->posts)->every(fn (ParsedPost $p) => $p->postedAt !== null))->toBeTrue()
        ->and(collect($page->posts)->filter(fn (ParsedPost $p) => $p->views !== null)->count())->toBeGreaterThan(15);
})->with(['telegram', 'durov']);

it('reads header info and pagination', function () {
    $page = (new ChannelFeedParser)->parse('testbuilder', fixtureContents('telegram/feed-synthetic.html'));

    expect($page->title)->toBe('Test Builder')
        ->and($page->description)->toBe('Shipping small apps every week.')
        ->and($page->subscribers)->toBe(12_300)
        ->and($page->olderCursor)->toBe(100)
        ->and($page->posts)->toHaveCount(6);
});

it('parses text, links, hashtags, views and dates', function () {
    $post = syntheticFeed()[100];

    expect($post->text)->toContain('Launched Chapa Bot v1 today!')
        ->and($post->text)->toContain('🚀')
        ->and($post->text)->not->toContain('alert(1)')
        ->and($post->textHtml)->toContain('<b>Chapa Bot</b>')
        ->and($post->textHtml)->toContain('rel="nofollow noopener ugc"')
        ->and($post->textHtml)->not->toContain('<script')
        ->and($post->links)->toBe(['https://github.com/test/chapa-bot'])
        ->and($post->hashtags)->toBe(['launch', 'laravel'])
        ->and($post->views)->toBe(12_300)
        ->and($post->postedAt->toIso8601String())->toBe('2026-03-01T09:30:00+00:00')
        ->and($post->isForward)->toBeFalse()
        ->and($post->edited)->toBeFalse();
});

it('detects forwards', function () {
    $post = syntheticFeed()[101];

    expect($post->isForward)->toBeTrue()
        ->and($post->forwardedFrom)->toBe('otherchannel')
        ->and($post->views)->toBe(915);
});

it('detects replies and edits and ignores the quoted reply text', function () {
    $post = syntheticFeed()[102];

    expect($post->replyToMsgId)->toBe(100)
        ->and($post->edited)->toBeTrue()
        ->and($post->text)->toBe('ሰላም ለሁላችሁ! ዛሬ አዲስ ባህሪ ጨምረናል።')
        ->and($post->views)->toBe(1_200_000);
});

it('flags service messages', function () {
    expect(syntheticFeed()[103]->isService)->toBeTrue();
});

it('detects polls and albums', function () {
    $posts = syntheticFeed();

    expect($posts[104]->mediaTypes)->toBe(['poll'])
        ->and($posts[104]->views)->toBe(3_400)
        ->and($posts[106]->mediaTypes)->toContain('photo', 'album')
        ->and($posts[106]->text)->toBe('New dashboard screens');
});

it('reads reactions best-effort', function () {
    expect(syntheticFeed()[106]->reactions)->toBe([
        ['emoji' => '🔥', 'count' => 45],
        ['emoji' => 'star', 'count' => 2000],
    ]);
});
