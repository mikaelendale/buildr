<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Services\Claims\OwnershipActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard/settings: timezone, notifications, delete account (plan §4, §6.6).
 * Slug changes live on the profile page (same review rules).
 */
class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $creator = $user->creator;

        return Inertia::render('dashboard/settings', [
            'settings' => [
                'timezone' => $creator->timezone ?? config('buildr.default_timezone'),
                'notify_summary_digest' => $user->notify_summary_digest,
                'bot_can_message' => $user->bot_can_message,
                'slug' => $creator?->slug,
            ],
            'botUsername' => config('buildr.telegram.bot_username'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'notify_summary_digest' => ['boolean'],
        ]);

        $user = $request->user();
        $user->forceFill(['notify_summary_digest' => (bool) ($data['notify_summary_digest'] ?? false), 'timezone' => $data['timezone']])->save();
        $user->creator?->forceFill(['timezone' => $data['timezone']])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings saved.']);

        return back();
    }

    public function destroy(Request $request, OwnershipActions $actions): RedirectResponse
    {
        $user = $request->user();
        $slug = $user->creator?->slug;

        $request->validate(
            ['confirm' => ['required', 'in:'.($slug ?? 'delete')], 'keep_channels' => ['boolean']],
            ['confirm.in' => 'Type your profile name to confirm.'],
        );

        $actions->deleteAccount($user, $request->boolean('keep_channels'));

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Your account was deleted.');
    }
}
