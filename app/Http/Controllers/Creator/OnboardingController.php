<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\CreatorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\CreatorBasicsRequest;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Tag;
use App\Services\Claims\ClaimException;
use App\Services\Claims\ClaimService;
use App\Services\Claims\OwnershipActions;
use App\Services\Creators\CreatorProfileService;
use App\Services\Creators\EditorData;
use App\Services\Creators\OnboardingProgress;
use App\Services\Creators\SummaryEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Six resumable onboarding steps (plan §6.3); state lives in creators.onboarding_step.
 */
class OnboardingController extends Controller
{
    public const STEPS = [1 => 'welcome', 2 => 'channel', 3 => 'verify', 4 => 'basics', 5 => 'building', 6 => 'review'];

    public function resume(Request $request): RedirectResponse
    {
        $creator = $request->user()->creator;

        if ($creator !== null && $creator->status !== CreatorStatus::Draft && $creator->status !== CreatorStatus::ChangesRequested) {
            return redirect('/dashboard');
        }

        return redirect('/onboarding/'.self::STEPS[$creator->onboarding_step ?? 1]);
    }

    public function show(Request $request, string $step, OnboardingProgress $progress, EditorData $editor, SummaryEditor $summaries): Response|RedirectResponse
    {
        $index = array_search($step, self::STEPS, true);
        $creator = $request->user()->creator;
        $reached = $creator->onboarding_step ?? 1;

        abort_if($index === false, 404);

        if ($index > $reached) {
            return redirect('/onboarding/'.self::STEPS[$reached]);
        }

        // Nothing left to build (e.g. an existing page that carried over on
        // claim): don't park the creator on the progress screen.
        if ($step === 'building' && $progress->for($creator)['ready']) {
            $this->advance($creator, 6);

            return redirect('/onboarding/review');
        }

        $props = match ($step) {
            'welcome' => [],
            'channel' => ['channels' => $creator?->channels()->pluck('username')->all() ?? []],
            'verify' => ['claim' => $this->claimPayload($this->latestClaim($creator, $request))],
            'basics' => $this->basicsProps($creator, $request),
            'building' => ['progress' => $progress->for($creator)],
            'review' => [
                'editor' => $editor->forCreator($creator),
                'regenerationsLeft' => $summaries->regenerationsLeftToday($request->user()),
                'creator' => ['name' => $creator->display_name, 'slug' => $creator->slug, 'status' => $creator->status->value, 'review_note' => $creator->review_note],
            ],
            default => abort(404),
        };

        return Inertia::render("onboarding/{$step}", $props + [
            'step' => $index,
            'steps' => array_values(self::STEPS),
        ]);
    }

    public function start(Request $request, OwnershipActions $actions): RedirectResponse
    {
        $creator = $actions->draftCreatorFor($request->user());
        $this->advance($creator, 2);

        return redirect('/onboarding/channel');
    }

    public function addChannel(Request $request, ClaimService $claims): RedirectResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        try {
            $claims->start($request->user(), (string) $request->input('username'));
        } catch (ClaimException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()])->errorBag('default');
        }

        $this->advance($request->user()->creator, 3);

        return redirect('/onboarding/verify');
    }

    public function saveBasics(CreatorBasicsRequest $request, CreatorProfileService $profiles, OnboardingProgress $progress): RedirectResponse
    {
        $creator = $request->user()->creator;
        $profiles->updateBasics($creator, $request->validated());

        // A channel already in the directory keeps its profile when claimed,
        // so there is nothing to build: straight on to the review.
        if ($progress->for($creator)['ready']) {
            $this->advance($creator, 6);

            return redirect('/onboarding/review');
        }

        $this->advance($creator, 5);

        return redirect('/onboarding/building');
    }

    public function continueToReview(Request $request): RedirectResponse
    {
        $this->advance($request->user()->creator, 6);

        return redirect('/onboarding/review');
    }

    public function submit(Request $request, CreatorProfileService $profiles): RedirectResponse
    {
        $creator = $request->user()->creator;

        if (! $profiles->submit($creator)) {
            throw ValidationException::withMessages(['submit' => 'Verify at least one channel before submitting.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Submitted! We\'ll message you on Telegram once it\'s reviewed.']);

        return redirect('/dashboard');
    }

    public function progress(Request $request, OnboardingProgress $progress): JsonResponse
    {
        $creator = $request->user()->creator;

        abort_if($creator === null, 404);

        return response()->json($progress->for($creator));
    }

    /**
     * Live progress for every channel still being built (dashboard polling).
     */
    public function channelsProgress(Request $request, OnboardingProgress $progress): JsonResponse
    {
        $creator = $request->user()->creator;

        abort_if($creator === null, 404);

        return response()->json(['channels' => $progress->building($creator)]);
    }

    private function advance(?Creator $creator, int $step): void
    {
        if ($creator !== null && $creator->onboarding_step < $step) {
            $creator->forceFill(['onboarding_step' => $step])->save();
        }
    }

    private function latestClaim(?Creator $creator, Request $request): ?Claim
    {
        return Claim::where('user_id', $request->user()->id)
            ->where('purpose', ClaimPurpose::Claim)
            ->whereIn('status', [ClaimStatus::Pending, ClaimStatus::Verified])
            ->latest()
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function claimPayloadFor(?Claim $claim): ?array
    {
        if ($claim === null) {
            return null;
        }

        return [
            'id' => $claim->id,
            'code' => $claim->code,
            'username' => $claim->channel_username,
            'purpose' => $claim->purpose->value,
            'status' => $claim->status->value,
            'attempts' => $claim->attempts,
            'expires_at' => $claim->expires_at->toIso8601String(),
            'last_checked_at' => $claim->last_checked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimPayload(?Claim $claim): ?array
    {
        return self::claimPayloadFor($claim);
    }

    /**
     * @return array<string, mixed>
     */
    private function basicsProps(Creator $creator, Request $request): array
    {
        $creator->load(['socials', 'tags']);

        return [
            'basics' => [
                'display_name' => $creator->display_name,
                'real_name' => $creator->real_name,
                'real_name_am' => $creator->real_name_am,
                'slug' => $creator->slug,
                'bio' => $creator->bio,
                'location' => $creator->location,
                'timezone' => $creator->timezone ?? config('buildr.default_timezone'),
                'socials' => $creator->socials->map(fn ($s) => ['url' => $s->url, 'platform' => $s->platform->value])->values()->all(),
                'tags' => $creator->tags->pluck('name')->all(),
            ],
            'suggestedTags' => Tag::curated()->pluck('name'),
            'telegramName' => $request->user()->name,
        ];
    }
}
