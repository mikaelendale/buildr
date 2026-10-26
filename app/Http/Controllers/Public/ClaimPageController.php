<?php

namespace App\Http\Controllers\Public;

use App\Enums\ClaimPurpose;
use App\Http\Controllers\Controller;
use App\Services\Claims\ClaimException;
use App\Services\Claims\ClaimService;
use App\Support\TelegramUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /claim and /remove explainers (plan §9.7), and starting a removal claim.
 */
class ClaimPageController extends Controller
{
    public function claim(Request $request): Response
    {
        return Inertia::render('public/claim', [
            'channel' => TelegramUsername::normalize((string) $request->query('channel')),
        ]);
    }

    public function remove(Request $request): Response
    {
        return Inertia::render('public/remove', [
            'channel' => TelegramUsername::normalize((string) $request->query('channel')),
        ]);
    }

    public function startRemoval(Request $request, ClaimService $claims): RedirectResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        try {
            $claim = $claims->start($request->user(), (string) $request->input('username'), ClaimPurpose::Removal);
        } catch (ClaimException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        return redirect('/claims/'.$claim->id);
    }
}
