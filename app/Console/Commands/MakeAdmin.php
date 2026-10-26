<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * The only way to create (or reset) an admin account: there is no admin signup
 * route (plan §2). 2FA is enforced on first login.
 */
#[Signature('buildr:make-admin {email} {--name=} {--reset-2fa : Clear the admin\'s 2FA so it must be set up again} {--generate : Make a random password and print it once (automatic without a terminal, e.g. on Laravel Cloud)}')]
#[Description('Create an admin account, or reset an existing admin\'s password')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        // Remote runners (cloud command:run) have no terminal to type a password into.
        $generate = $this->option('generate') || ! $this->input->isInteractive();

        $secret = $generate
            ? Str::password(24, symbols: false)
            : password(label: 'Password (min 12 characters)', required: true, validate: fn (string $v) => mb_strlen($v) < 12 ? 'Use at least 12 characters.' : null);

        $user = User::firstOrNew(['email' => $email]);
        $exists = $user->exists;

        $user->forceFill([
            'name' => $this->option('name') ?: ($user->name ?? Str::before($email, '@')),
            'role' => UserRole::Admin,
            'password' => $secret,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if ($this->option('reset-2fa')) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
        }

        $user->save();

        $this->info($exists ? "Admin {$email} updated." : "Admin {$email} created. Log in at /admin/login and set up 2FA.");

        if ($generate) {
            $this->line("Password (shown once): {$secret}");
            $this->line('Change it after your first login at /admin/security.');
        }

        return self::SUCCESS;
    }
}
