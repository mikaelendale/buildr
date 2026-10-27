<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Product news through the bot, for /admin/announcements and
 * buildr:announce: who it reaches (creators connected to the bot who
 * haven't paused it or muted updates), the message as Telegram shows it,
 * and the fan-out (one queued DM each, logged as an Announcement).
 */
class Announcer
{
    public const FOOTER = 'Mute these in Settings on Buildr.';

    public function __construct(private readonly TelegramNotifier $notifier) {}

    /**
     * @return Builder<User>
     */
    public function audience(): Builder
    {
        return User::query()
            ->where('role', UserRole::Creator)
            ->whereNotNull('telegram_id')
            ->where('bot_can_message', true)
            ->where('notify_product_updates', true)
            ->whereNull('banned_at');
    }

    /**
     * How the creator base splits for the admin page.
     *
     * @return array{reach: int, connected: int, muted: int, paused: int, creators: int}
     */
    public function counts(): array
    {
        $creators = User::where('role', UserRole::Creator)->whereNull('banned_at');
        $connected = (clone $creators)->whereNotNull('telegram_id');

        return [
            'reach' => $this->audience()->count(),
            'connected' => (clone $connected)->count(),
            'muted' => (clone $connected)->where('bot_can_message', true)->where('notify_product_updates', false)->count(),
            'paused' => (clone $connected)->where('bot_can_message', false)->count(),
            'creators' => $creators->count(),
        ];
    }

    /**
     * Plain text from the admin page: escaped, line breaks kept.
     */
    public static function fromText(string $text): string
    {
        return e(trim(str_replace("\r\n", "\n", $text)));
    }

    /**
     * The DM as sent: a megaphone, the message, and how to mute.
     */
    public function compose(string $html): string
    {
        return "📣 {$html}\n\n<i>".self::FOOTER.'</i>';
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    public static function buttons(?string $label, ?string $url): array
    {
        return filled($label) && filled($url) ? [['text' => (string) $label, 'url' => (string) $url]] : [];
    }

    /**
     * Queue it for everyone in the audience and log it.
     */
    public function send(string $html, ?string $buttonLabel = null, ?string $buttonUrl = null, ?User $by = null): Announcement
    {
        $message = $this->compose($html);
        $buttons = self::buttons($buttonLabel, $buttonUrl);
        $recipients = 0;

        $this->audience()->lazyById()->each(function (User $user) use ($message, $buttons, &$recipients) {
            if ($this->notifier->toUser($user, $message, $buttons)) {
                $recipients++;
            }
        });

        $announcement = Announcement::create([
            'user_id' => $by?->id,
            'body' => $html,
            'button_label' => $buttons === [] ? null : $buttonLabel,
            'button_url' => $buttons === [] ? null : $buttonUrl,
            'recipients' => $recipients,
        ]);

        AuditLog::record('announcement.sent', $announcement, ['recipients' => $recipients], $by);

        return $announcement;
    }

    /**
     * One copy to a single connected account, to see it in Telegram first.
     */
    public function test(string $html, ?string $buttonLabel, ?string $buttonUrl, User $to): bool
    {
        return $this->notifier->toUser($to, $this->compose($html), self::buttons($buttonLabel, $buttonUrl), essential: true);
    }
}
