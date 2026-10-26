<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Report;
use App\Services\Admin\AdminActions;
use App\Services\Claims\OwnershipActions;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/reports (plan §10.6): dismiss, edit content, hide section,
 * unpublish, remove + blocklist, ban. A resolution note is required and the
 * reporter is DM'd if they were logged in.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');

        $reports = Report::with(['target', 'reporter'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->oldest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/reports', [
            'reports' => $reports->through(function (Report $r) {
                $target = $r->target;

                return [
                    'id' => $r->id,
                    'reason' => $r->reason->value,
                    'reason_label' => $r->reason->label(),
                    'details' => $r->details,
                    'status' => $r->status->value,
                    'contact' => $r->reporter_contact,
                    'reporter' => $r->reporter?->only(['id', 'name', 'telegram_username']),
                    'resolution_note' => $r->resolution_note,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'target' => match (true) {
                        $target instanceof Creator => ['type' => 'creator', 'id' => $target->id, 'name' => $target->display_name, 'url' => '/'.$target->slug, 'admin_url' => '/admin/creators/'.$target->id],
                        $target instanceof Channel => ['type' => 'channel', 'id' => $target->id, 'name' => '@'.$target->username, 'url' => '/c/'.$target->username, 'admin_url' => '/admin/channels/'.$target->id],
                        default => null,
                    },
                ];
            }),
            'status' => $status,
        ]);
    }

    public function resolve(Request $request, Report $report, AdminActions $actions, OwnershipActions $ownership, TelegramNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'resolve', 'unpublish', 'remove', 'ban'])],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $target = $report->target;
        $admin = $request->user();

        match ($data['action']) {
            'unpublish' => $target instanceof Creator ? $actions->unpublishCreator($target, $admin, $data['note']) : ($target instanceof Channel ? $actions->hideChannel($target, $admin) : null),
            'remove' => $target instanceof Channel ? $ownership->removeChannel($target->username, $admin, 'Report #'.$report->id.': '.$data['note']) : null,
            'ban' => $target instanceof Creator && $target->user !== null ? $actions->ban($target->user, $admin, $data['note']) : null,
            default => null,
        };

        $report->forceFill([
            'status' => $data['action'] === 'dismiss' ? ReportStatus::Dismissed : ReportStatus::Resolved,
            'resolved_by' => $admin->id,
            'resolution_note' => $data['note'],
            'resolved_at' => now(),
        ])->save();

        AuditLog::record('report.'.$data['action'], $report, ['note' => $data['note']], $admin);

        if ($report->reporter !== null) {
            $notifier->toUser($report->reporter, 'Thanks for your report. '.($data['action'] === 'dismiss'
                ? 'We looked into it and decided not to change anything.'
                : 'We reviewed it and took action.'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Report closed.']);

        return back();
    }
}
