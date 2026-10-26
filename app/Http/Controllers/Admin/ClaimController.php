<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Services\Claims\ClaimException;
use App\Services\Claims\ClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/claims (plan §10.5): every claim with status; disputes show both
 * parties and are resolved by whoever posts a fresh code first.
 */
class ClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $claims = Claim::with(['user', 'channel.creator.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->query('purpose')))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/claims', [
            'claims' => $claims->through(fn (Claim $c) => [
                'id' => $c->id,
                'username' => $c->channel_username,
                'purpose' => $c->purpose->value,
                'status' => $c->status->value,
                'code' => $c->code,
                'attempts' => $c->attempts,
                'found_in' => $c->found_in,
                'user' => ['id' => $c->user?->id, 'name' => $c->user?->name, 'telegram' => $c->user?->telegram_username],
                'owner' => $c->channel?->creator?->user?->only(['id', 'name', 'telegram_username']),
                'created_at' => $c->created_at?->toIso8601String(),
                'expires_at' => $c->expires_at->toIso8601String(),
                'verified_at' => $c->verified_at?->toIso8601String(),
            ]),
            'filters' => $request->only(['status', 'purpose']),
        ]);
    }

    /**
     * For a claim stuck as "disputed": issue fresh codes to both parties.
     */
    public function startResolution(Request $request, Claim $claim, ClaimService $claims): RedirectResponse
    {
        try {
            $claims->openDispute($claim->user, $claim->channel_username);
        } catch (ClaimException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        $claim->forceFill(['status' => ClaimStatus::Failed])->save();
        AuditLog::record('claim.resolution_started', $claim, [], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Both parties got a fresh code by DM.']);

        return back();
    }

    public function cancel(Request $request, Claim $claim): RedirectResponse
    {
        $claim->forceFill(['status' => ClaimStatus::Failed])->save();
        AuditLog::record('claim.cancelled', $claim, ['purpose' => $claim->purpose->value], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Claim cancelled.']);

        return back();
    }

    public function check(Request $request, Claim $claim, ClaimService $claims): RedirectResponse
    {
        $claims->check($claim);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checked: '.$claim->fresh()?->status->value]);

        return back();
    }
}
