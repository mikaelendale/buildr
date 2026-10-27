<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:announce {message : Telegram HTML (<b>, <i>, <a href>)} {--send : Actually send; without it, only count who would get it}')]
#[Description('Message every creator connected to the bot about something new on Buildr (skips anyone who muted updates)')]
class Announce extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        $message = trim((string) $this->argument('message'));

        if ($message === '') {
            $this->error('The message is empty.');

            return self::FAILURE;
        }

        $audience = User::query()
            ->where('role', UserRole::Creator)
            ->whereNotNull('telegram_id')
            ->where('bot_can_message', true)
            ->where('notify_product_updates', true)
            ->whereNull('banned_at');

        if (! $this->option('send')) {
            $this->info("Would send to {$audience->count()} creators. Run again with --send to send.");
            $this->line($message);

            return self::SUCCESS;
        }

        $sent = 0;

        $audience->each(function (User $user) use ($notifier, $message, &$sent) {
            if ($notifier->announce($user, "📣 {$message}\n\n<i>Mute these in Settings on Buildr.</i>")) {
                $sent++;
            }
        });

        $this->info("Queued {$sent} messages.");

        return self::SUCCESS;
    }
}
