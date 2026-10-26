<?php

namespace App\Support;

/**
 * Turns whatever a person pastes ("@handle", "t.me/handle", a full post URL,
 * "https://t.me/s/handle?before=10") into a lowercase Telegram username.
 */
final class TelegramUsername
{
    public const PATTERN = '/^[a-z][a-z0-9_]{3,31}$/';

    public static function normalize(?string $input): ?string
    {
        $value = trim((string) $input);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^(https?://)?(www\.)?(t\.me|telegram\.me|telegram\.dog)/#i', '', $value) ?? $value;
        $value = ltrim($value, '@');
        $value = preg_replace('#^s/#i', '', $value) ?? $value;
        $value = preg_split('#[/?\#\s]#', $value)[0] ?? '';
        $value = strtolower($value);

        return self::isValid($value) ? $value : null;
    }

    public static function isValid(string $username): bool
    {
        return preg_match(self::PATTERN, $username) === 1;
    }

    /**
     * True when the input looks like a handle or t.me link rather than a name.
     */
    public static function looksLikeHandle(string $input): bool
    {
        $input = trim($input);

        return str_starts_with($input, '@')
            || preg_match('#(t\.me|telegram\.me)/#i', $input) === 1;
    }
}
