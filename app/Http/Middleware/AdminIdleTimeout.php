<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin sessions end after 2 hours without activity (plan §5.2).
 */
class AdminIdleTimeout
{
    private const KEY = 'admin_last_activity';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $last = (int) $request->session()->get(self::KEY, 0);
        $limit = (int) config('buildr.admin.idle_timeout_minutes') * 60;

        // A session revived by a "remember me" cookie has no activity yet:
        // admins sign in again (password + 2FA) instead.
        $expired = ($last > 0 && time() - $last > $limit)
            || ($last === 0 && Auth::guard('web')->viaRemember());

        if ($expired) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/admin/login')->with('status', 'You were logged out after 2 hours of inactivity.');
        }

        $request->session()->put(self::KEY, time());

        return $next($request);
    }
}
