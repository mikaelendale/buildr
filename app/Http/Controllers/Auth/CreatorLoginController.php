<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Creator\TelegramController;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creator accounts: email + password on the web. Owning a channel is proven
 * separately (the claim code in the channel bio), so the account itself
 * needs nothing from Telegram; connecting the bot (offered right after
 * signing in) adds password resets and updates. Admins sign in at
 * /admin/login (with 2FA) and are refused here.
 */
class CreatorLoginController extends Controller
{
    private const REMEMBER_MINUTES = 60 * 24 * 30;

    public function create(Request $request): Response
    {
        // Only a path on this site ("/onboarding"), never an absolute or
        // protocol-relative URL: that would be an open redirect after login.
        $intended = (string) $request->query('intended', '');

        if (preg_match('~^/(?![/\\\\])~', $intended) === 1) {
            $request->session()->put('url.intended', url($intended));
        }

        return Inertia::render('auth/creator-login', [
            'mode' => $request->query('mode') === 'register' ? 'register' : 'login',
            'devLogin' => app()->environment('local'),
            'status' => $request->session()->get('status'),
            'support' => config('buildr.support_telegram'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $email = Str::lower($data['email']);
        // Per address and IP, and per address alone (an IP can be spoofed or shared).
        $key = 'creator-login:'.$email.'|'.$request->ip();
        $emailKey = 'creator-login-email:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, 6) || RateLimiter::tooManyAttempts($emailKey, 20)) {
            throw ValidationException::withMessages(['email' => 'Too many tries. Wait a few minutes and try again.']);
        }

        $user = User::where('email', $email)->first();

        // Admins sign in at /admin/login (with 2FA); here they get the same
        // answer as a wrong password, so this form never confirms theirs.
        if ($user === null || $user->isAdmin() || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($emailKey, 15 * 60);

            throw ValidationException::withMessages(['email' => 'That email and password don’t match an account.']);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($emailKey);

        return $this->signIn($request, $user, 'password');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->max(200)],
        ], [
            'email.unique' => 'There’s already an account with that email. Log in instead.',
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
        ]);

        $user->forceFill(['role' => UserRole::Creator])->save();

        return $this->signIn($request, $user, 'register');
    }

    /**
     * Local-only shortcut to a test creator.
     */
    public function devLogin(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $id = (int) $request->input('id', 1000001);
        $user = User::firstOrCreate(['telegram_id' => $id], [
            'name' => (string) $request->input('first_name', 'Dev').' Creator',
            'telegram_username' => (string) $request->input('username', 'dev_creator_'.$id),
        ]);

        abort_if($user->isAdmin(), 403);

        return $this->signIn($request, $user, 'dev');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function signIn(Request $request, User $user, string $method): RedirectResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();

        LoginEvent::create([
            'user_id' => $user->id,
            'method' => $method,
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        if ($user->isBanned()) {
            return redirect('/')->with('status', 'Your account is suspended.');
        }

        Auth::guard('web')->setRememberDuration(self::REMEMBER_MINUTES);
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        // They just typed it: sensitive steps right after (connecting Telegram) don't ask again.
        $request->session()->passwordConfirmed();

        $default = $user->creator()->exists() ? '/dashboard' : '/onboarding';

        // Offer the bot first (password resets and updates live there), then carry on.
        if (TelegramController::shouldPrompt($user, $method)) {
            $request->session()->put('telegram.next', $request->session()->pull('url.intended', url($default)));

            return redirect('/connect-telegram');
        }

        return redirect()->intended($default);
    }
}
