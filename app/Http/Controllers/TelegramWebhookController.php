<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\LinkResult;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramLinker;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Bot updates (plan §5.3). Requests without the matching secret-token header
 * are rejected. Handles /start (and "/start link_<token>" from the site's
 * Connect Telegram button), /reset, /stop, /help and "blocked the bot"
 * events.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot, TelegramLinker $linker): JsonResponse
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
        $from = $message['from'] ?? null;

        if ($chatId === null || ! is_array($from) || ! isset($from['id']) || ($message['chat']['type'] ?? '') !== 'private') {
            return response()->json(['ok' => true]);
        }

        [$command, $argument] = array_pad(explode(' ', $text, 2), 2, '');
        $command = strtolower((string) preg_replace('/@\w+$/', '', $command));

        if ($command === '/start' && str_starts_with($argument, TelegramLinker::PREFIX)) {
            $this->link($bot, $chatId, $linker, substr($argument, strlen(TelegramLinker::PREFIX)), $from);

            return response()->json(['ok' => true]);
        }

        $user = User::where('telegram_id', (int) $from['id'])->first();

        match ($command) {
            '/start' => $this->start($bot, $chatId, $user),
            '/reset' => $this->reset($bot, $chatId, $user),
            '/stop' => $this->stop($bot, $chatId, $user),
            '/help' => $this->help($bot, $chatId, $user),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function link(TelegramBot $bot, int|string $chatId, TelegramLinker $linker, string $token, array $from): void
    {
        $result = $linker->complete($token, $from);

        $reply = match ($result) {
            LinkResult::Linked => "✅ <b>Telegram connected.</b>\n\nPassword resets and updates about your Buildr page come here now. Send /reset any time you forget your password, /stop to pause updates.",
            LinkResult::Expired => 'That link has expired. Go back to Buildr and press Connect Telegram again.',
            LinkResult::Taken => 'This Telegram account is already connected to another Buildr account, so we left it there. Message us if that’s not right.',
        };

        $this->reply($bot, $chatId, $reply, $result === LinkResult::Linked ? [['text' => 'Open my dashboard', 'url' => url('/dashboard')]] : []);
    }

    private function start(TelegramBot $bot, int|string $chatId, ?User $user): void
    {
        if ($user === null) {
            $this->reply($bot, $chatId, "👋 Welcome to Buildr!\n\nTo get password resets and updates here, log in on the site and press <b>Connect Telegram</b>.", [['text' => 'Log in to Buildr', 'url' => url('/login')]]);

            return;
        }

        $user->forceFill(['bot_can_message' => true])->save();
        $this->reply($bot, $chatId, "👋 You're connected. We'll message you here about your Buildr page.\n\nForgot your password? Send /reset.", [['text' => 'Open my dashboard', 'url' => url('/dashboard')]]);
    }

    /**
     * A reset link straight from the bot, for connected accounts.
     */
    private function reset(TelegramBot $bot, int|string $chatId, ?User $user): void
    {
        if ($user === null || $user->isAdmin() || $user->isBanned() || $user->email === null) {
            $this->reply($bot, $chatId, "This Telegram isn't connected to a Buildr account, so we can't reset a password from here.\n\nIf you can still log in, connect Telegram from your settings first.");

            return;
        }

        /** @var PasswordBroker $broker */
        $broker = Password::broker();

        if ($broker->getRepository()->recentlyCreatedToken($user)) {
            $this->reply($bot, $chatId, 'We just sent you a reset link above. Give it a minute before asking for another.');

            return;
        }

        $user->sendPasswordResetNotification($broker->createToken($user));
    }

    private function stop(TelegramBot $bot, int|string $chatId, ?User $user): void
    {
        $user?->forceFill(['bot_can_message' => false])->save();
        $this->reply($bot, $chatId, "Updates are paused. You'll still get a password reset if you ask for one. Send /start any time to turn updates back on.");
    }

    private function help(TelegramBot $bot, int|string $chatId, ?User $user): void
    {
        $this->reply($bot, $chatId, implode("\n", [
            '<b>Buildr bot</b>',
            '',
            '/reset: get a link to choose a new password',
            '/stop: pause updates',
            '/start: turn updates back on',
            '',
            $user === null
                ? 'Not connected yet: log in on the site and press Connect Telegram.'
                : 'Connected to your Buildr account.',
        ]));
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

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    private function reply(TelegramBot $bot, int|string $chatId, string $html, array $buttons = []): void
    {
        if ($bot->isConfigured()) {
            $bot->sendMessage($chatId, $html, $buttons === [] ? [] : [
                'reply_markup' => ['inline_keyboard' => array_map(fn (array $button) => [$button], $buttons)],
            ]);
        }
    }
}
