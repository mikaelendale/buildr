<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Jobs\Media\StoreUserAvatar;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Telegram\TelegramLoginVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creator login with the Telegram Login Widget (plan §5.1). No passwords.
 */
class TelegramLoginController extends Controller
{
    private const REMEMBER_MINUTES = 60 * 24 * 30;

    public function create(Request $request): Response
    {
        if ($request->filled('intended')) {
            $request->session()->put('url.intended', url((string) $request->query('intended')));
        }

        return Inertia::render('auth/telegram-login', [
            'botUsername' => config('buildr.telegram.bot_username'),
            'devLogin' => app()->environment('local'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, TelegramLoginVerifier $verifier): RedirectResponse
    {
        $payload = $request->only(['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash']);

        if (! $verifier->verify($payload)) {
            throw ValidationException::withMessages(['telegram' => 'We could not verify your Telegram login. Please try again.']);
        }

        return $this->login($request, $payload);
    }

    /**
     * Local-only shortcut: the widget needs a real domain registered with BotFather.
     */
    public function devLogin(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $id = (int) $request->input('id', 1000001);

        return $this->login($request, [
            'id' => $id,
            'first_name' => (string) $request->input('first_name', 'Dev'),
            'last_name' => 'Creator',
            'username' => (string) $request->input('username', 'dev_creator_'.$id),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function login(Request $request, array $payload): RedirectResponse
    {
        $first = trim((string) ($payload['first_name'] ?? ''));
        $last = trim((string) ($payload['last_name'] ?? ''));

        $user = User::firstOrNew(['telegram_id' => (int) $payload['id']]);

        abort_if($user->exists && $user->isAdmin(), 403);

        $user->forceFill([
            'name' => trim("{$first} {$last}") ?: ($payload['username'] ?? 'Telegram user'),
            'first_name' => $first ?: null,
            'last_name' => $last ?: null,
            'telegram_username' => isset($payload['username']) ? Str::lower((string) $payload['username']) : null,
            'role' => $user->role ?? UserRole::Creator,
            'last_login_at' => now(),
        ])->save();

        LoginEvent::create([
            'user_id' => $user->id,
            'method' => 'telegram',
            'ip_hash' => hash('sha256', $request->ip().config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        if (filled($payload['photo_url'] ?? null)) {
            StoreUserAvatar::dispatch($user->id, (string) $payload['photo_url']);
        }

        if ($user->isBanned()) {
            return redirect('/')->with('status', 'Your account is suspended.');
        }

        Auth::guard('web')->setRememberDuration(self::REMEMBER_MINUTES);
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        $fallback = $user->creator()->exists() ? '/dashboard' : '/onboarding';

        return redirect()->intended($fallback);
    }
}
