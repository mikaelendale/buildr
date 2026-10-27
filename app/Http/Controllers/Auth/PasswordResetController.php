<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgot password, Telegram style: the reset link goes to the account's
 * connected bot chat (User::sendPasswordResetNotification), never by
 * email. The page never says whether an account exists or is connected.
 */
class PasswordResetController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'sent' => (bool) $request->session()->get('reset_sent', false),
            'email' => $request->session()->get('reset_email'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        $email = Str::lower($data['email']);

        // Sent, throttled or unknown: the answer looks the same.
        Password::sendResetLink(['email' => $email]);

        return back()->with('reset_sent', true)->with('reset_email', $email);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request, TelegramNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->max(200)],
        ]);

        $data['email'] = Str::lower($data['email']);

        // Admins reset with buildr:make-admin; their accounts never take a link.
        if (User::where('email', $data['email'])->first()?->isAdmin()) {
            throw ValidationException::withMessages(['email' => 'This link is invalid or has expired.']);
        }

        $changed = null;

        $status = Password::reset($data, function (User $user, string $password) use (&$changed) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
            $changed = $user;
        });

        if ($status !== Password::PASSWORD_RESET || $changed === null) {
            throw ValidationException::withMessages(['email' => 'This link is invalid or has expired. Ask the bot for a new one with /reset.']);
        }

        $notifier->passwordChanged($changed);

        if ($request->user()?->is($changed)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Password changed.']);

            return redirect('/dashboard/settings');
        }

        return redirect('/login')->with('status', 'Password changed. Log in with your new password.');
    }
}
