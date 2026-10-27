<?php

namespace App\Console\Commands;

use App\Services\Notifications\Announcer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('buildr:announce {message : Telegram HTML (<b>, <i>, <a href>)} {--button= : Button label} {--url= : Button link} {--send : Actually send; without it, only count who would get it}')]
#[Description('Message every creator connected to the bot about something new on Buildr (same as /admin/announcements; skips anyone who muted updates)')]
class Announce extends Command
{
    public function handle(Announcer $announcer): int
    {
        $message = trim((string) $this->argument('message'));

        if ($message === '') {
            $this->error('The message is empty.');

            return self::FAILURE;
        }

        if (! $this->option('send')) {
            $this->info("Would send to {$announcer->audience()->count()} creators. Run again with --send to send.");
            $this->line($announcer->compose($message));

            return self::SUCCESS;
        }

        $announcement = $announcer->send($message, $this->option('button'), $this->option('url'));

        $this->info("Queued {$announcement->recipients} messages.");

        return self::SUCCESS;
    }
}
