<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Queues DMs to creators and alerts to the admin chat (plan §13). Telegram
 * is the only channel: there is no mailer. Users who paused the bot
 * (bot_can_message = false) are skipped, except for messages they asked
 * for themselves, like a password reset; the dashboard shows banners
 * instead.
 */
class TelegramNotifier
{
    /**
     * @param  list<array{text: string, url: string}>  $buttons  one URL button per row
     */
    public function toUser(?User $user, string $html, array $buttons = [], bool $essential = false): bool
    {
        if ($user === null || $user->telegram_id === null || $user->isBanned() || (! $essential && ! $user->bot_can_message)) {
            return false;
        }

        SendTelegramMessage::dispatch($user->telegram_id, $html, $user->id, $buttons);

        return true;
    }

    /**
     * A product announcement: only to people who kept updates on.
     */
    public function announce(User $user, string $html): bool
    {
        return $user->notify_product_updates && $this->toUser($user, $html);
    }

    public function passwordReset(User $user, string $token): bool
    {
        if ($user->isAdmin() || $user->email === null) {
            return false;
        }

        $minutes = (int) config('auth.passwords.users.expire', 60);
        $url = url('/reset-password/'.$token).'?'.http_build_query(['email' => $user->email]);

        return $this->toUser(
            $user,
            "🔐 <b>Reset your Buildr password</b>\n\nThe button works for {$minutes} minutes. Didn't ask for this? Ignore it, your password stays the same.",
            [['text' => 'Choose a new password', 'url' => $url]],
            essential: true,
        );
    }

    public function passwordChanged(User $user): bool
    {
        return $this->toUser(
            $user,
            "🔐 Your Buildr password was just changed.\n\nNot you? Send /reset here to set a new one right away.",
            essential: true,
        );
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
