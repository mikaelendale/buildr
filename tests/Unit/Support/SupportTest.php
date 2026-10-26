<?php

use App\Enums\LinkKind;
use App\Enums\PostLang;
use App\Support\AdHeuristics;
use App\Support\CountParser;
use App\Support\HtmlSanitizer;
use App\Support\LanguageDetector;
use App\Support\LinkClassifier;
use App\Support\Similarity;
use App\Support\TelegramUsername;

it('normalizes telegram handles and links', function (string $input, ?string $expected) {
    expect(TelegramUsername::normalize($input))->toBe($expected);
})->with([
    ['@Durov', 'durov'],
    ['durov', 'durov'],
    ['t.me/durov', 'durov'],
    ['https://t.me/durov/531', 'durov'],
    ['https://t.me/s/durov?before=10', 'durov'],
    ['  https://www.telegram.me/Some_Channel  ', 'some_channel'],
    ['ab', null],
    ['1abc', null],
    ['', null],
    ['https://example.com/durov', null],
]);

it('parses telegram counters', function (string $input, ?int $expected) {
    expect(CountParser::parse($input))->toBe($expected);
})->with([
    ['915', 915],
    ['12.3K', 12_300],
    ['6.99M', 6_990_000],
    ['1.2B', 1_200_000_000],
    ['10 249 235 subscribers', 10_249_235],
    ['3,400', 3_400],
    ['n/a', null],
]);

it('detects post language from script ratios', function (string $text, ?PostLang $expected) {
    expect(LanguageDetector::detect($text))->toBe($expected);
})->with([
    ['Shipping the new dashboard today', PostLang::En],
    ['ሰላም ለሁላችሁ! ዛሬ አዲስ ባህሪ ጨምረናል።', PostLang::Am],
    ['ዛሬ new feature ጨምረናል for the dashboard launch', PostLang::Mixed],
    ['Привет, сегодня запуск', PostLang::Other],
    ['🚀🚀🚀', null],
    ['ሰላም https://github.com/some/very-long-repository-name #launch', PostLang::Am],
]);

it('classifies links', function (string $url, LinkKind $kind) {
    expect(LinkClassifier::classify($url))->toBe($kind);
})->with([
    ['https://github.com/a/b', LinkKind::Github],
    ['https://x.com/someone', LinkKind::X],
    ['https://twitter.com/someone', LinkKind::X],
    ['https://t.me/channel/1', LinkKind::Telegram],
    ['https://youtu.be/xyz', LinkKind::Youtube],
    ['https://www.producthunt.com/posts/thing', LinkKind::Product],
    ['https://myapp.vercel.app', LinkKind::Product],
    ['https://example.com', LinkKind::Website],
    ['not a url', LinkKind::Other],
]);

it('sanitizes post html with a strict allowlist', function () {
    $html = '<b>Bold</b> <strong>strong</strong> <a href="javascript:alert(1)">x</a> <a href="https://ok.dev">ok</a><br><img src=x onerror=alert(1)><span style="x">kept text</span><script>bad()</script>';

    $clean = HtmlSanitizer::clean($html);

    expect($clean)->toBe('<b>Bold</b> <b>strong</b> x <a href="https://ok.dev" rel="nofollow noopener ugc" target="_blank">ok</a><br>kept text');
});

it('converts post html to text', function () {
    expect(HtmlSanitizer::toText('Line one<br/>Line &amp; two<br><b>bold</b>'))->toBe("Line one\nLine & two\nbold");
});

it('spots obvious ads but not normal launches', function () {
    expect(AdHeuristics::looksLikeAd('Sponsored by Acme. Use code BUILD for 20% off'))->toBeTrue()
        ->and(AdHeuristics::looksLikeAd('#ማስታወቂያ አዲስ ኮርስ'))->toBeTrue()
        ->and(AdHeuristics::looksLikeAd('Launched my app today, you can buy the pro plan here', 3))->toBeFalse();
});

it('scores trigram similarity like pg_trgm', function () {
    expect(Similarity::trigram('chapa bot', 'chapa bot'))->toBe(1.0)
        ->and(Similarity::trigram('chapa', 'chapa bot'))->toBeGreaterThan(0.5)
        ->and(Similarity::trigram('laravel', 'figma'))->toBeLessThan(0.1)
        ->and(Similarity::bestWindow('abebe', 'abebe kebede'))->toBe(1.0);
});
