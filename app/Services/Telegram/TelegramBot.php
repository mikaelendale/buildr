<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Bot API client. The bot is only used for the Login Widget and DMs;
 * it never reads channels (plan §0).
 */
class TelegramBot
{
    public function isConfigured(): bool
    {
        return filled(config('buildr.telegram.bot_token'));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function sendMessage(int|string $chatId, string $html, array $extra = []): Response
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ] + $extra);
    }

    public function setWebhook(string $url, string $secret): Response
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member'],
        ]);
    }

    public function getMe(): Response
    {
        return $this->call('getMe');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload = []): Response
    {
        $token = config('buildr.telegram.bot_token');

        if (! filled($token)) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::timeout(15)
            ->asJson()
            ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
    }
}
