<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Queues DMs to creators and alerts to the admin chat (plan §13).
 * Users who blocked the bot (bot_can_message = false) are skipped; the
 * dashboard shows banners instead.
 */
class TelegramNotifier
{
    public function toUser(?User $user, string $html): bool
    {
        if ($user === null || $user->telegram_id === null || ! $user->bot_can_message || $user->isBanned()) {
            return false;
        }

        SendTelegramMessage::dispatch($user->telegram_id, $html, $user->id);

        return true;
    }

    public function toAdmin(string $html): void
    {
        Log::channel('buildr')->notice('Admin alert: '.strip_tags($html));

        $chatId = config('buildr.telegram.admin_chat_id');

        if (filled($chatId)) {
            SendTelegramMessage::dispatch($chatId, '🛠 '.$html);
        }
    }
}
