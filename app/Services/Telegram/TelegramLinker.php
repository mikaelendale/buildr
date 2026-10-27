<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connects an email account to the bot. The site hands out a one-time
 * t.me/<bot>?start=link_<token> link (only the token's hash is stored);
 * pressing Start sends "/start link_<token>" from the person's own
 * Telegram account, which proves both sides belong to the same person.
 */
class TelegramLinker
{
    public const PREFIX = 'link_';

    public const TTL_MINUTES = 30;

    public function startUrl(User $user): string
    {
        $token = Str::random(32);

        $user->forceFill([
            'telegram_link_token' => hash('sha256', $token),
            'telegram_link_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ])->save();

        return $this->botUrl().'?start='.self::PREFIX.$token;
    }

    public function botUrl(): string
    {
        return 'https://t.me/'.ltrim((string) config('buildr.telegram.bot_username'), '@');
    }

    /**
     * Attach the Telegram account that sent /start link_<token>.
     *
     * @param  array<string, mixed>  $from  the message's `from` object
     */
    public function complete(string $token, array $from): LinkResult
    {
        $telegramId = (int) ($from['id'] ?? 0);

        $user = User::where('telegram_link_token', hash('sha256', $token))
            ->where('telegram_link_expires_at', '>', now())
            ->first();

        if ($user === null || $telegramId === 0) {
            return LinkResult::Expired;
        }

        $holder = User::where('telegram_id', $telegramId)->whereKeyNot($user->id)->first();

        // A Telegram-only or admin account would be locked out without it; keep it there.
        if ($holder !== null && ($holder->isAdmin() || $holder->password === null)) {
            return LinkResult::Taken;
        }

        DB::transaction(function () use ($user, $holder, $telegramId, $from) {
            // Same person, new account: the link moves over.
            $holder?->forceFill(['telegram_id' => null, 'bot_can_message' => false, 'telegram_linked_at' => null])->save();

            $user->forceFill([
                'telegram_id' => $telegramId,
                'telegram_username' => $from['username'] ?? null,
                'bot_can_message' => true,
                'telegram_linked_at' => now(),
                'telegram_link_token' => null,
                'telegram_link_expires_at' => null,
            ])->save();
        });

        return LinkResult::Linked;
    }

    public function disconnect(User $user): void
    {
        $user->forceFill([
            'telegram_id' => null,
            'telegram_username' => null,
            'bot_can_message' => false,
            'telegram_linked_at' => null,
            'telegram_link_token' => null,
            'telegram_link_expires_at' => null,
        ])->save();
    }
}
