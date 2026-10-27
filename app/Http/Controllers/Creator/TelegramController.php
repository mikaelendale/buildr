<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\TelegramLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Connecting an account to the bot: the step offered right after login,
 * the button that opens Telegram with a one-time /start link, a status
 * check the page polls until the bot confirms, and disconnecting.
 */
class TelegramController extends Controller
{
    /** How long "Not now" holds before the step is offered again at login. */
    public const PROMPT_EVERY_DAYS = 14;

    /**
     * Offer the step after this sign-in? Always after signing up; otherwise
     * at most every two weeks, and never once connected.
     */
    public static function shouldPrompt(User $user, string $method): bool
    {
        return filled(config('buildr.telegram.bot_username'))
            && ! $user->hasTelegram()
            && ($method === 'register' || $user->telegram_prompted_at === null || $user->telegram_prompted_at->lt(now()->subDays(self::PROMPT_EVERY_DAYS)));
    }

    public function prompt(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $next = $this->next($request);

        if ($user->hasTelegram()) {
            return redirect($next);
        }

        $user->forceFill(['telegram_prompted_at' => now()])->save();

        return Inertia::render('auth/connect-telegram', [
            'next' => $next,
            'connected' => $user->hasTelegram(),
            'username' => $user->telegram_username,
        ]);
    }

    public function skip(Request $request): RedirectResponse
    {
        return redirect((string) $request->session()->pull('telegram.next', $this->fallback($request->user())));
    }

    /**
     * Opens Telegram on the bot with a fresh one-time link.
     */
    public function connect(Request $request, TelegramLinker $linker): HttpResponse
    {
        abort_unless(filled(config('buildr.telegram.bot_username')), 503, 'The Telegram bot is not set up.');

        // Inertia-aware: after the password prompt this arrives as an XHR.
        return Inertia::location($linker->startUrl($request->user()));
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user()->fresh() ?? $request->user();

        return response()->json([
            'connected' => $user->hasTelegram(),
            'username' => $user->telegram_username,
            'can_message' => $user->bot_can_message,
        ]);
    }

    public function disconnect(Request $request, TelegramLinker $linker): RedirectResponse
    {
        $linker->disconnect($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram disconnected. You won’t get resets or updates there.']);

        return back();
    }

    private function next(Request $request): string
    {
        return (string) $request->session()->get('telegram.next', $this->fallback($request->user()));
    }

    private function fallback(User $user): string
    {
        return url($user->creator()->exists() ? '/dashboard' : '/onboarding');
    }
}
