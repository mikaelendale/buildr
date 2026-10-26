<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify only handles admin accounts (email + password + TOTP) under /admin.
 * Creators log in with the Telegram Login Widget (TelegramLoginController).
 */
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Only admins with a password can use the Fortify login, and banned accounts never can.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower((string) $request->input('email')))
                ->where('role', UserRole::Admin)
                ->first();

            if ($user === null || $user->password === null || $user->isBanned()) {
                return null;
            }

            if (! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            $user->forceFill(['last_login_at' => now()])->save();

            LoginEvent::create([
                'user_id' => $user->id,
                'method' => 'password',
                'ip_hash' => hash('sha256', $request->ip().config('app.key')),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
