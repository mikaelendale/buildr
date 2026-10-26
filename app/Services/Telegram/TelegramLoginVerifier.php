<?php

namespace App\Services\Telegram;

/**
 * Verifies Telegram Login Widget payloads (plan §5.1):
 *
 *   data_check_string = every field except `hash`, sorted, "key=value" joined by "\n"
 *   secret_key        = SHA256(bot_token)
 *   expected          = HMAC_SHA256(data_check_string, secret_key) as hex
 *
 * Compared with hash_equals; payloads older than 24h are rejected (replay).
 */
class TelegramLoginVerifier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, ?string $botToken = null): bool
    {
        $botToken ??= (string) config('buildr.telegram.bot_token');
        $hash = $payload['hash'] ?? null;

        if ($botToken === '' || ! is_string($hash) || $hash === '' || ! isset($payload['id'], $payload['auth_date'])) {
            return false;
        }

        $authDate = (int) $payload['auth_date'];
        $maxAge = (int) config('buildr.telegram.auth_max_age_seconds', 86400);

        if ($authDate <= 0 || time() - $authDate > $maxAge || $authDate > time() + 60) {
            return false;
        }

        return hash_equals($this->sign($payload, $botToken), $hash);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload, string $botToken): string
    {
        unset($payload['hash']);

        $fields = array_filter($payload, fn ($value) => is_scalar($value) && $value !== '');
        ksort($fields);

        $dataCheckString = implode("\n", array_map(
            fn ($key, $value) => "{$key}={$value}",
            array_keys($fields),
            $fields,
        ));

        return hash_hmac('sha256', $dataCheckString, hash('sha256', $botToken, true));
    }
}
