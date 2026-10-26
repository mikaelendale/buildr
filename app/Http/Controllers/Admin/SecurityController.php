<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Admin password + mandatory TOTP setup (plan §5.2). Every other admin page
 * redirects here until 2FA is confirmed.
 */
class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $request->ensureStateIsValid();

        return Inertia::render('admin/security', [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'twoFactorEnabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'mustSetUp' => ! $request->user()->hasConfirmedTwoFactor(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->password]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Password updated.']);

        return back();
    }
}
