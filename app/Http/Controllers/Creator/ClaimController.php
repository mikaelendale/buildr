<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Services\Claims\ClaimException;
use App\Services\Claims\ClaimService;
use App\Services\Claims\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Claim codes outside onboarding: extra channels from the dashboard, the
 * "Check now" button, disputes, and the slug availability check.
 */
class ClaimController extends Controller
{
    public function store(Request $request, ClaimService $claims): RedirectResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        try {
            $claim = $claims->start($request->user(), (string) $request->input('username'));
        } catch (ClaimException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        return redirect('/claims/'.$claim->id);
    }

    public function show(Request $request, Claim $claim): Response
    {
        abort_unless($claim->user_id === $request->user()->id, 404);

        return Inertia::render('dashboard/claim', [
            'claim' => OnboardingController::claimPayloadFor($claim),
        ]);
    }

    public function dispute(Request $request, ClaimService $claims): RedirectResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        try {
            [$mine] = $claims->openDispute($request->user(), (string) $request->input('username'));
        } catch (ClaimException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        return redirect('/claims/'.$mine->id);
    }

    /**
     * POST /api/claims/{claim}/check: throttled to ~1 per 20s per claim and 30/day per user.
     */
    public function check(Request $request, Claim $claim, ClaimService $claims): JsonResponse
    {
        abort_unless($claim->user_id === $request->user()->id, 404);

        $claim = $claims->check($claim);
        $creator = $request->user()->creator()->first();

        if ($claim->status === ClaimStatus::Verified && $claim->purpose === ClaimPurpose::Claim && $creator !== null && $creator->onboarding_step < 4) {
            $creator->forceFill(['onboarding_step' => 4])->save();
        }

        return response()->json([
            'claim' => OnboardingController::claimPayloadFor($claim),
            'verified' => $claim->status === ClaimStatus::Verified,
            'message' => match ($claim->status) {
                ClaimStatus::Verified => 'Verified! You can remove the code from your channel now.',
                ClaimStatus::Expired => 'This code expired. Start again to get a new one.',
                ClaimStatus::Disputed => 'Someone else already claimed this channel. Our team will sort it out.',
                ClaimStatus::Failed => 'This check failed. Contact us if you think that is a mistake.',
                default => 'Not found yet. Telegram can take a minute to update.',
            },
        ]);
    }

    /**
     * ?slug=… checks the creator's own profile link; add &channel={id} to
     * check a short link for one of their channels instead.
     */
    public function slug(Request $request, SlugService $slugs): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug')));
        $creator = $request->user()->creator;

        if ($request->filled('channel')) {
            $channel = $creator?->channels()->whereKey((int) $request->query('channel'))->first();

            abort_if($channel === null, 404);

            $problem = $slug === $channel->slug ? null : $slugs->problem($slug, ignoreChannel: $channel);
        } else {
            $problem = $slugs->problem($slug, $creator);
        }

        return response()->json(['available' => $problem === null, 'message' => $problem]);
    }
}
