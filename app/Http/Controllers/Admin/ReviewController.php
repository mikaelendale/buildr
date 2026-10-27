<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelReviewStatus;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\CreatorStatus;
use App\Enums\SummaryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\ProcessChannel;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Summary;
use App\Services\Admin\AdminActions;
use App\Services\Admin\ReviewFlags;
use App\Services\Creators\EditorData;
use App\Services\Profiles\ProfilePageBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review queue (plan §10.2): pending creators, unclaimed channels ready to
 * publish, failed verifications and post-publish field changes.
 */
class ReviewController extends Controller
{
    public const CHANGE_TEMPLATES = [
        'Please add at least one social link so readers can find you elsewhere.',
        'Your bio is empty. Add a sentence about what you build.',
        'Some summary sections are inaccurate. Please edit or hide them.',
        'Your real name looks incomplete. Use the name you go by publicly, or leave it empty.',
    ];

    public const REJECT_TEMPLATES = [
        'We could not confirm this is a builder or creator channel.',
        'The channel content goes against our terms.',
        'This looks like an impersonation of another creator.',
    ];

    public function index(ReviewFlags $flags): Response
    {
        $creators = Creator::where('status', CreatorStatus::PendingReview)->with('user')->orderBy('submitted_at')->get();
        $channels = Channel::where('review_status', ChannelReviewStatus::Pending)->where('claim_status', ChannelClaimStatus::Unclaimed)->orderBy('updated_at')->get();
        $fieldChanges = Creator::whereNotNull('pending_changes')->orderBy('updated_at')->get();
        $failed = Summary::where('status', SummaryStatus::FailedVerification)
            ->whereIn('id', Summary::selectRaw('max(id)')->groupBy('channel_id'))
            ->with('channel')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('admin/review/index', [
            'creators' => $creators->map(fn (Creator $c) => [
                'id' => $c->id,
                'name' => $c->display_name,
                'slug' => $c->slug,
                'submitted_at' => $c->submitted_at?->toIso8601String(),
                'channels' => $c->channels()->pluck('username'),
                'profile' => $this->profileState($c),
                'flags' => $flags->forCreator($c),
            ]),
            'channels' => $channels->map(fn (Channel $c) => [
                'id' => $c->id,
                'username' => $c->username,
                'title' => $c->displayTitle(),
                'subscribers' => $c->subscribers,
                'processed_at' => $c->last_processed_at?->toIso8601String(),
                'flags' => $flags->forChannel($c),
            ]),
            'fieldChanges' => $fieldChanges->map(fn (Creator $c) => [
                'id' => $c->id,
                'name' => $c->display_name,
                'slug' => $c->slug,
                'changes' => collect($c->pending_changes)->map(fn ($new, $field) => ['field' => $field, 'old' => $c->{$field}, 'new' => $new])->values(),
            ]),
            'failed' => $failed->map(fn (Summary $s) => [
                'summary_id' => $s->id,
                'channel_id' => $s->channel_id,
                'username' => $s->channel?->username,
                'version' => $s->version,
                'created_at' => $s->created_at?->toIso8601String(),
                'creator_id' => $s->channel?->creator_id,
                // What the fact-checker took out, and whether a good profile is still live.
                'missing' => array_values(array_filter(['hook', 'intro'], fn (string $key) => blank($s->content[$key] ?? null))),
                'dropped' => count((array) ($s->verification['dropped'] ?? [])),
                'has_live' => Summary::where('channel_id', $s->channel_id)->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])->exists(),
            ]),
        ]);
    }

    public function showCreator(Creator $creator, ProfilePageBuilder $pages, EditorData $editor, ReviewFlags $flags): Response
    {
        $creator->load(['user', 'socials', 'tags']);

        return Inertia::render('admin/review/creator', [
            'creator' => [
                'id' => $creator->id,
                'name' => $creator->display_name,
                'slug' => $creator->slug,
                'status' => $creator->status->value,
                'review_note' => $creator->review_note,
                'submitted_at' => $creator->submitted_at?->toIso8601String(),
                'pending_changes' => $creator->pending_changes,
                'user' => [
                    'id' => $creator->user?->id,
                    'name' => $creator->user?->name,
                    'telegram_username' => $creator->user?->telegram_username,
                    'created_at' => $creator->user?->created_at?->toIso8601String(),
                    'banned' => $creator->user?->isBanned(),
                ],
            ],
            'profile' => $pages->creator($creator),
            'editor' => $editor->forCreator($creator),
            'verification' => $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->get()->map(fn (Channel $c) => [
                'username' => $c->username,
                'report' => $c->latestSummary()->first()?->verification,
                'claim' => Claim::where('channel_id', $c->id)->where('purpose', ClaimPurpose::Claim)->where('status', ClaimStatus::Verified)->latest('verified_at')->first()?->only(['code', 'verified_at', 'found_in', 'found_msg_id']),
            ]),
            'flags' => $flags->forCreator($creator),
            'templates' => ['changes' => self::CHANGE_TEMPLATES, 'reject' => self::REJECT_TEMPLATES],
        ]);
    }

    public function showChannel(Channel $channel, ProfilePageBuilder $pages, EditorData $editor, ReviewFlags $flags): Response
    {
        $channel->load(['overrides', 'stats']);

        return Inertia::render('admin/review/channel', [
            'channel' => $pages->channel($channel) + [
                'visibility' => $channel->visibility->value,
                'review_status' => $channel->review_status?->value,
            ],
            'editor' => $editor->forChannel($channel),
            'verification' => $channel->latestSummary()->first()?->verification,
            'flags' => $flags->forChannel($channel),
        ]);
    }

    public function approve(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $actions->approveCreator($creator, $request->user(), $request->boolean('feature'));

        return $this->next('Approved and published.');
    }

    public function requestChanges(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $actions->requestChanges($creator, $request->user(), $data['note']);

        return $this->next('Changes requested.');
    }

    public function reject(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $actions->rejectCreator($creator, $request->user(), $data['reason']);

        return $this->next('Rejected.');
    }

    public function approveFields(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $actions->approveFieldChanges($creator, $request->user());

        return $this->next('Changes applied.');
    }

    public function rejectFields(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $actions->rejectFieldChanges($creator, $request->user(), $request->string('reason')->toString() ?: null);

        return $this->next('Changes rejected.');
    }

    public function publishChannel(Request $request, Channel $channel, AdminActions $actions): RedirectResponse
    {
        $actions->publishChannel($channel, $request->user(), $request->boolean('feature'));

        return $this->next("@{$channel->username} published.");
    }

    public function rejectChannel(Request $request, Channel $channel, AdminActions $actions): RedirectResponse
    {
        $actions->rejectChannel($channel, $request->user(), $request->string('reason')->toString() ?: null);

        return $this->next("@{$channel->username} won't be published.");
    }

    public function approveSummary(Request $request, Summary $summary, AdminActions $actions): RedirectResponse
    {
        $actions->approveSummary($summary, $request->user());

        return $this->next('Summary approved.');
    }

    public function regenerate(Request $request, Channel $channel): RedirectResponse
    {
        ProcessChannel::dispatch($channel->id, 'summary_start', null, 1, 'admin');
        AuditLog::record('summary.regenerate_requested', $channel, [], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Regenerating. It will reappear in the queue if verification fails.']);

        return back();
    }

    /**
     * The same action on many queue items at once. Each one goes through
     * the single-item action (messages, audit log); items that changed
     * state since the page loaded are skipped.
     */
    public function bulk(Request $request, AdminActions $actions): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve_creators', 'publish_channels', 'regenerate', 'approve_summaries'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $admin = $request->user();
        $ids = array_values(array_unique($data['ids']));
        $done = 0;

        foreach ($ids as $id) {
            switch ($data['action']) {
                case 'approve_creators':
                    if ($creator = Creator::where('status', CreatorStatus::PendingReview)->find($id)) {
                        $actions->approveCreator($creator, $admin);
                        $done++;
                    }
                    break;
                case 'publish_channels':
                    if ($channel = Channel::where('review_status', ChannelReviewStatus::Pending)->find($id)) {
                        $actions->publishChannel($channel, $admin);
                        $done++;
                    }
                    break;
                case 'regenerate':
                    if ($channel = Channel::find($id)) {
                        ProcessChannel::dispatch($channel->id, 'summary_start', null, 1, 'admin');
                        AuditLog::record('summary.regenerate_requested', $channel, ['bulk' => true], $admin);
                        $done++;
                    }
                    break;
                case 'approve_summaries':
                    if ($summary = Summary::where('status', SummaryStatus::FailedVerification)->find($id)) {
                        $actions->approveSummary($summary, $admin);
                        $done++;
                    }
                    break;
            }
        }

        $what = match ($data['action']) {
            'approve_creators' => 'Approved and published',
            'publish_channels' => 'Published',
            'regenerate' => 'Rewriting',
            'approve_summaries' => 'Approved',
        };
        $skipped = count($ids) - $done;

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$what} {$done}.".($skipped > 0 ? " Skipped {$skipped} that had already changed." : '')]);

        return redirect('/admin/review');
    }

    /**
     * Whether a submission's profile is ready: every claimed channel has a
     * good summary (ready), one is still being written (writing), or the
     * latest draft failed the fact-check (failed).
     */
    private function profileState(Creator $creator): string
    {
        $states = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->get()->map(function (Channel $channel) {
            if ($channel->summaries()->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])->exists()) {
                return 'ready';
            }

            return match ($channel->summaries()->latest('id')->first()?->status) {
                null => 'none',
                SummaryStatus::FailedVerification => 'failed',
                default => 'writing',
            };
        });

        return match (true) {
            $states->isEmpty() => 'none',
            $states->contains('failed') => 'failed',
            $states->contains('writing') || $states->contains('none') => 'writing',
            default => 'ready',
        };
    }

    private function next(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect('/admin/review');
    }
}
