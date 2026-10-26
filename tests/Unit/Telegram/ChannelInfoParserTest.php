<?php

use App\Enums\TelegramPageType;
use App\Services\Telegram\ChannelInfoParser;

it('parses a public channel page', function () {
    $info = (new ChannelInfoParser)->parse('durov', fixtureContents('telegram/channel-info-durov.html'));

    expect($info->pageType)->toBe(TelegramPageType::Channel)
        ->and($info->title)->toBe('Pavel Durov')
        ->and($info->description)->toBe('Founder of Telegram.')
        ->and($info->subscribers)->toBe(10_249_235)
        ->and($info->hasPreview)->toBeTrue()
        ->and($info->avatarUrl)->toStartWith('https://cdn');
});

it('recognises bots', function () {
    $info = (new ChannelInfoParser)->parse('botfather', fixtureContents('telegram/bot-botfather.html'));

    expect($info->pageType)->toBe(TelegramPageType::Bot)
        ->and($info->subscribers)->toBeNull();
});

it('recognises usernames that do not exist', function () {
    $info = (new ChannelInfoParser)->parse('nobody', fixtureContents('telegram/not-found.html'));

    expect($info->pageType)->toBe(TelegramPageType::NotFound)
        ->and($info->title)->toBeNull();
});

it('recognises groups and users', function (string $extra, string $action, TelegramPageType $type) {
    $html = <<<HTML
        <div class="tgme_page">
          <div class="tgme_page_title"><span dir="auto">Someone</span></div>
          <div class="tgme_page_extra">{$extra}</div>
          <div class="tgme_page_action"><a class="tgme_action_button_new">{$action}</a></div>
        </div>
        HTML;

    expect((new ChannelInfoParser)->parse('x', $html)->pageType)->toBe($type);
})->with([
    'group' => ['1 234 members, 56 online', 'View in Telegram', TelegramPageType::Group],
    'user' => ['@someone', 'Send Message', TelegramPageType::User],
]);
