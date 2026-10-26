<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Points the bot at /telegram/webhook with the secret token (plan §5.3).
 */
#[Signature('buildr:set-webhook {--url= : Override the public base URL}')]
#[Description('Register the Telegram bot webhook with its secret token')]
class SetTelegramWebhook extends Command
{
    public function handle(TelegramBot $bot): int
    {
        $secret = (string) config('buildr.telegram.webhook_secret');

        if (! $bot->isConfigured() || $secret === '') {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET first.');

            return self::FAILURE;
        }

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/telegram/webhook';
        $response = $bot->setWebhook($url, $secret);

        if (! $response->successful() || ! $response->json('ok')) {
            $this->error('Telegram said: '.$response->body());

            return self::FAILURE;
        }

        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
