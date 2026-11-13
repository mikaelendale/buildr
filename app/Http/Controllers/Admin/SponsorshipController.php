<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SponsorshipStatus;
use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Sponsorships\SponsorshipException;
use App\Services\Sponsorships\SponsorshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin sponsorship management: approve/reject pending requests, end active sponsorships.
 */
class SponsorshipController extends Controller
{
    /**
     * GET /admin/featured/sponsorships
     *
     * Display pending sponsorship requests and active sponsorships.
     */
    public function featured(SponsorshipService $service): Response
    {
        $pending = Sponsorship::where('status', SponsorshipStatus::Pending->value)
            ->with('channel', 'requestedBy.creator')
            ->latest()
            ->get();

        $active = Sponsorship::active()
            ->with('channel', 'requestedBy.creator')
            ->latest('starts_at')
            ->get();

        return Inertia::render('admin/sponsorships', [
            'pending' => $pending->map(fn (Sponsorship $s) => [
                'id' => $s->id,
                'channel_id' => $s->channel_id,
                'channel_username' => $s->channel->username,
                'channel_title' => $s->channel->displayTitle(),
                'creator_id' => $s->channel->creator_id,
                'creator_name' => $s->channel->creator->display_name ?? 'Unknown',
                'requested_at' => $s->created_at?->toIso8601String(),
                'review_note' => $s->review_note,
            ]),
            'active' => $active->map(fn (Sponsorship $s) => [
                'id' => $s->id,
                'channel_id' => $s->channel_id,
                'channel_username' => $s->channel->username,
                'channel_title' => $s->channel->displayTitle(),
                'creator_id' => $s->channel->creator_id,
                'creator_name' => $s->channel->creator->display_name ?? 'Unknown',
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /admin/sponsorships/{sponsorship}/approve
     *
     * Approve a pending sponsorship request.
     */
    public function approve(Request $request, Sponsorship $sponsorship, SponsorshipService $service): RedirectResponse
    {
        try {
            $service->approve($sponsorship, $request->user());
        } catch (SponsorshipException $e) {
            throw ValidationException::withMessages(['sponsorship' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Approved sponsorship for @{$sponsorship->channel->username}."]);

        return back();
    }

    /**
     * POST /admin/sponsorships/{sponsorship}/reject
     *
     * Reject a pending sponsorship request.
     */
    public function reject(Request $request, Sponsorship $sponsorship, SponsorshipService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $service->reject($sponsorship, $request->user(), $data['reason']);
        } catch (SponsorshipException $e) {
            throw ValidationException::withMessages(['sponsorship' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rejected sponsorship for @{$sponsorship->channel->username}."]);

        return back();
    }

    /**
     * POST /admin/sponsorships/{sponsorship}/end
     *
     * End an active sponsorship.
     */
    public function end(Request $request, Sponsorship $sponsorship, SponsorshipService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $service->end($sponsorship, $request->user(), $data['reason'] ?? null);
        } catch (SponsorshipException $e) {
            throw ValidationException::withMessages(['sponsorship' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Ended sponsorship for @{$sponsorship->channel->username}."]);

        return back();
    }
}
