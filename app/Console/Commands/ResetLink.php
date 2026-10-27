<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

#[Signature('buildr:reset-link {email : The creator account to reset}')]
#[Description('Print a password reset link for a creator who never connected Telegram (check it is really them first)')]
class ResetLink extends Command
{
    public function handle(): int
    {
        $user = User::where('email', Str::lower((string) $this->argument('email')))->first();

        if ($user === null || $user->isAdmin()) {
            $this->error('No creator account with that email.');

            return self::FAILURE;
        }

        $token = Password::broker()->createToken($user);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        $this->line(url('/reset-password/'.$token).'?'.http_build_query(['email' => $user->email]));
        $this->info("Works for {$minutes} minutes, once.".($user->hasTelegram() ? ' (They have Telegram connected: /reset in the bot also works.)' : ''));

        return self::SUCCESS;
    }
}
