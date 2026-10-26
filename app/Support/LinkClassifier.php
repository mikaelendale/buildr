<?php

namespace App\Support;

use App\Enums\LinkKind;

final class LinkClassifier
{
    private const PRODUCT_DOMAINS = [
        'producthunt.com', 'apps.apple.com', 'play.google.com', 'chromewebstore.google.com',
        'chrome.google.com', 'marketplace.visualstudio.com', 'gumroad.com', 'lemonsqueezy.com',
        'vercel.app', 'netlify.app', 'pages.dev', 'itch.io', 'npmjs.com', 'pypi.org', 'packagist.org',
    ];

    public static function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    public static function classify(string $url): LinkKind
    {
        $domain = self::domain($url);

        if ($domain === null) {
            return LinkKind::Other;
        }

        return match (true) {
            self::is($domain, ['github.com', 'gist.github.com', 'gitlab.com']) => LinkKind::Github,
            self::is($domain, ['x.com', 'twitter.com']) => LinkKind::X,
            self::is($domain, ['t.me', 'telegram.me', 'telegram.dog']) => LinkKind::Telegram,
            self::is($domain, ['youtube.com', 'youtu.be', 'm.youtube.com']) => LinkKind::Youtube,
            self::is($domain, self::PRODUCT_DOMAINS) => LinkKind::Product,
            default => LinkKind::Website,
        };
    }

    /**
     * @param  list<string>  $domains
     */
    private static function is(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
