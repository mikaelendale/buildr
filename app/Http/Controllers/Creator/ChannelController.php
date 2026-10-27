<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ChannelClaimStatus;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Services\Claims\ClaimException;
use App\Services\Claims\ClaimService;
use App\Services\Claims\OwnershipActions;
use App\Services\Claims\SlugService;
use App\Services\Creators\OnboardingProgress;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Og\OgImageRenderer;
use App\Services\Profiles\ProfilePageBuilder;
use App\Support\TelegramUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard/channels: add + verify more, set primary, rename, remove (plan
 * §4, §7.6), and each channel's own page: short link, visibility, and
 * whether it names its owner.
 */
class ChannelController extends Controller
{
    public function index(Request $request, ProfilePageBuilder $pages, OnboardingProgress $progress, SlugService $slugs): Response
    {
        $user = $request->user();
        $creator = $user->creator;

        return Inertia::render('dashboard/channels', [
            'channels' => $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->with('creator.user')->get()
                ->map(fn (Channel $c) => $pages->channelChip($c, $c->id === $creator->primary_channel_id) + [
                    'id' => $c->id,
                    'availability' => $c->availability->value,
                    'last_scraped_at' => $c->last_scraped_at?->toIso8601String(),
                    'page' => [
                        'slug' => $c->slug,
                        'suggested_slug' => $c->slug === null ? $slugs->suggestForChannel($c) : null,
                        'visible' => $c->page_visible,
                        'show_owner' => $c->show_owner,
                        'live' => $c->hasPublicPage(),
                    ],
                ])->values(),
            'profileUrl' => $creator->url(),
            'profileLive' => $creator->isPublished(),
            'claims' => Claim::where('user_id', $user->id)
                ->whereIn('purpose', [ClaimPurpose::Claim, ClaimPurpose::Rename, ClaimPurpose::Dispute])
                ->where('status', ClaimStatus::Pending)
                ->where('expires_at', '>', now())
                ->latest()
                ->get()
                ->map(fn (Claim $c) => OnboardingController::claimPayloadFor($c))
                ->values(),
            'maxChannels' => (int) config('buildr.max_channels_per_user'),
            'building' => $progress->building($creator),
        ]);
    }

    /**
     * The channel's own page: a short link (/{slug}, or none for
     * /c/{username}), whether it's public, and whether it names the owner.
     */
    public function page(Request $request, Channel $channel, SlugService $slugs, DirectoryIndexer $indexer, OgImageRenderer $og): RedirectResponse
    {
        $this->owned($request, $channel);

        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:30'],
            'visible' => ['required', 'boolean'],
            'show_owner' => ['required', 'boolean'],
        ]);

        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = $slug === '' ? null : $slug;

        if ($slug !== null && $slug !== $channel->slug && ($problem = $slugs->problem($slug, ignoreChannel: $channel)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        $channel->forceFill([
            'slug' => $slug,
            'page_visible' => (bool) $data['visible'],
            'show_owner' => (bool) $data['show_owner'],
        ])->save();

        $indexer->refreshChannel($channel);
        $og->refreshForChannel($channel);

        Inertia::flash('toast', ['type' => 'success', 'message' => $channel->page_visible
            ? "Saved. @{$channel->username} lives at ".preg_replace('~^https?://~', '', $channel->publicUrl()).'.'
            : "Saved. @{$channel->username}'s page is hidden."]);

        return back();
    }

    public function primary(Request $request, Channel $channel, DirectoryIndexer $indexer): RedirectResponse
    {
        $creator = $this->owned($request, $channel);
        $creator->forceFill(['primary_channel_id' => $channel->id])->save();
        $indexer->refreshCreator($creator);

        Inertia::flash('toast', ['type' => 'success', 'message' => "@{$channel->username} is now your main channel."]);

        return back();
    }

    /**
     * Rename: re-verify with a code on the new handle; known posts must match (plan §7.6).
     */
    public function rename(Request $request, Channel $channel, ClaimService $claims): RedirectResponse
    {
        $this->owned($request, $channel);
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        $new = TelegramUsername::normalize((string) $request->input('username'));

        if ($new === null || $new === $channel->username) {
            throw ValidationException::withMessages(['username' => 'Enter the channel\'s new @handle.']);
        }

        if (Channel::where('username', $new)->exists()) {
            throw ValidationException::withMessages(['username' => "@{$new} is already listed on Buildr."]);
        }

        try {
            $claim = $claims->startRename($request->user(), $channel, $new);
        } catch (ClaimException $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        return redirect('/claims/'.$claim->id);
    }

    public function destroy(Request $request, Channel $channel, OwnershipActions $actions): RedirectResponse
    {
        $this->owned($request, $channel);
        $request->validate(['confirm' => ['required', 'in:'.$channel->username]], ['confirm.in' => 'Type the channel username to confirm.']);

        $actions->removeChannel($channel->username, $request->user(), 'Owner removed from dashboard');

        Inertia::flash('toast', ['type' => 'success', 'message' => "@{$channel->username} was removed and blocklisted."]);

        return redirect('/dashboard/channels');
    }

    private function owned(Request $request, Channel $channel): Creator
    {
        $creator = $request->user()->creator;

        abort_unless($creator !== null && $channel->isClaimed() && $channel->creator_id === $creator->id, 403);

        return $creator;
    }
}
