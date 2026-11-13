<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Sponsorship;
use App\Services\Sponsorships\SponsorshipException;
use App\Services\Sponsorships\SponsorshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Creator-facing sponsorship actions: request and cancel.
 *
 * Authorization is enforced by the service (same ownership rules as
 * Creator\ChannelController::owned()); the controller is kept thin.
 */
class SponsorshipController extends Controller
{
    /**
     * POST /dashboard/channels/{channel}/sponsorship
     *
     * Rate-limited to 10/min in routes/creator.php.
     */
    public function store(Request $request, Channel $channel, SponsorshipService $service): RedirectResponse
    {
        try {
            $service->request($request->user(), $channel);
        } catch (SponsorshipException $e) {
            throw ValidationException::withMessages(['channel' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promotion request submitted. We\'ll let you know when it\'s reviewed.']);

        return back();
    }

    /**
     * DELETE /dashboard/channels/{channel}/sponsorship
     */
    public function cancel(Request $request, Sponsorship $sponsorship, SponsorshipService $service): RedirectResponse
    {
        try {
            $service->cancel($request->user(), $sponsorship);
        } catch (SponsorshipException $e) {
            throw ValidationException::withMessages(['sponsorship' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promotion request cancelled.']);

        return back();
    }
}
