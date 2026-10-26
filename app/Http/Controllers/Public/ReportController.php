<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Report;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Report modal on every profile/channel page (plan §9.9). Rate limited to
 * 5/hour per IP; logged-in users are attached automatically.
 */
class ReportController extends Controller
{
    public function store(Request $request, TelegramNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['creator', 'channel'])],
            'target_id' => ['required', 'integer'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:200'],
        ]);

        $target = $data['target_type'] === 'creator'
            ? Creator::find((int) $data['target_id'])
            : Channel::find((int) $data['target_id']);

        abort_if($target === null, 404);

        $report = Report::create([
            'reporter_user_id' => $request->user()?->id,
            'reporter_contact' => $data['contact'] ?? null,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
        ]);

        $name = $target instanceof Creator ? $target->display_name : '@'.$target->username;
        $notifier->toAdmin("New report #{$report->id} ({$report->reason->label()}) on <b>".e($name).'</b>: '.url('/admin/reports'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Thanks. Our team will look at this within 72 hours.']);

        return back();
    }
}
