<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Banning blocks login (plan §12.2): a banned user is logged out on the next
 * request and sees the banned page.
 */
class EnsureNotBanned
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isBanned()) {
            return $next($request);
        }

        $reason = $user->ban_reason;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::render('public/banned', ['reason' => $reason])
            ->toResponse($request)
            ->setStatusCode(403);
    }
}
