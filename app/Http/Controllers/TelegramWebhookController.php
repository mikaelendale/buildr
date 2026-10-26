<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bot updates (plan §5.3). Requests without the matching secret-token header
 * are rejected. Handles /start, /stop and "blocked the bot" events.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot, TelegramNotifier $notifier): JsonResponse
    {
        $secret = (string) config('buildr.telegram.webhook_secret');

        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $update = $request->all();

        if (isset($update['my_chat_member'])) {
            $this->chatMemberUpdate($update['my_chat_member']);

            return response()->json(['ok' => true]);
        }

        $message = $update['message'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;

        if ($chatId === null || $fromId === null || ($message['chat']['type'] ?? '') !== 'private') {
            return response()->json(['ok' => true]);
        }

        $user = User::where('telegram_id', (int) $fromId)->first();

        if (str_starts_with($text, '/start')) {
            $user?->forceFill(['bot_can_message' => true])->save();

            $reply = $user !== null
                ? '👋 You\'re connected. We\'ll message you here about your Buildr profile. Your dashboard: '.url('/dashboard')
                : '👋 Welcome to Buildr! Log in to claim your channel: '.url('/login');

            $this->reply($bot, $chatId, $reply);
        } elseif (str_starts_with($text, '/stop')) {
            $user?->forceFill(['bot_can_message' => false])->save();
            $this->reply($bot, $chatId, 'Notifications are off. Send /start any time to turn them back on.');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function chatMemberUpdate(array $update): void
    {
        $status = $update['new_chat_member']['status'] ?? null;
        $fromId = $update['from']['id'] ?? null;

        if ($fromId === null || ($update['chat']['type'] ?? '') !== 'private') {
            return;
        }

        $canMessage = match ($status) {
            'kicked', 'left' => false,
            'member' => true,
            default => null,
        };

        if ($canMessage !== null) {
            User::where('telegram_id', (int) $fromId)->update(['bot_can_message' => $canMessage]);
        }
    }

    private function reply(TelegramBot $bot, int|string $chatId, string $text): void
    {
        if ($bot->isConfigured()) {
            $bot->sendMessage($chatId, e($text));
        }
    }
}
