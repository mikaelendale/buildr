<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA is mandatory for admins (plan §5.2): until TOTP is confirmed, every
 * admin page redirects to the security page where it can be set up.
 */
class EnsureTwoFactorConfirmed
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('admin.security')
                ->with('status', 'Set up two-factor authentication to continue.');
        }

        return $next($request);
    }
}
