<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Every DM goes through this queued job, so a failed send never blocks the
 * main flow (plan §13).
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public int|string $chatId,
        public string $html,
        public ?int $userId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramBot $bot): void
    {
        if (! $bot->isConfigured()) {
            Log::channel('buildr')->info('Telegram DM skipped (bot not configured)', ['chat' => $this->chatId, 'text' => $this->html]);

            return;
        }

        $response = $bot->sendMessage($this->chatId, $this->html);

        if ($response->successful()) {
            return;
        }

        // 403 = the user blocked the bot or never pressed /start.
        if ($response->status() === 403 && $this->userId !== null) {
            User::whereKey($this->userId)->update(['bot_can_message' => false]);

            return;
        }

        Log::channel('buildr')->warning('Telegram DM failed', [
            'chat' => $this->chatId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if ($response->status() === 429 || $response->serverError()) {
            $this->release(60);
        }
    }
}
