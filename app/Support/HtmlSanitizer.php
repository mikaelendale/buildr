<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Strict allowlist sanitizer for post HTML (plan §12.1): a, b, i, code, pre, br.
 * Links keep only http(s) hrefs and always get rel="nofollow noopener ugc".
 */
final class HtmlSanitizer
{
    private const RENAME = [
        'strong' => 'b',
        'em' => 'i',
        'u' => 'i',
        's' => 'i',
        'del' => 'i',
        'blockquote' => 'i',
    ];

    private const ALLOWED = ['a', 'b', 'i', 'code', 'pre', 'br'];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $source = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $source->loadHTML('<?xml encoding="utf-8"?><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $source->getElementById('root');

        if ($root === null) {
            return e(strip_tags($html));
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= self::render($child);
        }

        return trim($out);
    }

    private static function render(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return e($node->wholeText, false);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        // Telegram custom emoji: <i class="emoji"><b>🌍</b></i> → just the character.
        if ($tag === 'i' && str_contains($node->getAttribute('class'), 'emoji')) {
            return e($node->textContent, false);
        }

        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'video', 'audio', 'img'], true)) {
            return '';
        }

        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= self::render($child);
        }

        $tag = self::RENAME[$tag] ?? $tag;

        if (! in_array($tag, self::ALLOWED, true)) {
            return $inner;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));

            if (preg_match('#^https?://#i', $href) !== 1) {
                return $inner;
            }

            return '<a href="'.e($href, false).'" rel="nofollow noopener ugc" target="_blank">'.$inner.'</a>';
        }

        return "<{$tag}>{$inner}</{$tag}>";
    }

    /**
     * Plain text from Telegram post HTML: <br> → newline, entities decoded.
     */
    public static function toText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);

        return trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);
    }
}
