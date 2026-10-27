<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Creators\EditorData;
use App\Services\Creators\SummaryEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard/summary: review / edit / hide / regenerate AI sections (plan §4, §8.8).
 */
class SummaryController extends Controller
{
    public function index(Request $request, EditorData $editor, SummaryEditor $summaries): Response
    {
        return Inertia::render('dashboard/summary', [
            'editor' => $editor->forCreator($request->user()->creator),
            'regenerationsLeft' => $summaries->regenerationsLeftToday($request->user()),
        ]);
    }

    public function update(Request $request, Channel $channel, string $section, SummaryEditor $summaries): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        $request->validate(['value' => ['present']]);

        $summaries->edit($channel, $section, $request->input('value'), $request->user());

        return $this->done('Section saved.');
    }

    public function hide(Request $request, Channel $channel, string $section, SummaryEditor $summaries): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $summaries->hide($channel, $section, $request->user(), $request->boolean('hidden', true));

        return $this->done($request->boolean('hidden', true) ? 'Section hidden.' : 'Section visible again.');
    }

    public function reset(Request $request, Channel $channel, string $section, SummaryEditor $summaries): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $summaries->reset($channel, $section, $request->user());

        return $this->done('Back to the AI version.');
    }

    public function keep(Request $request, Channel $channel, string $section, SummaryEditor $summaries): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $summaries->keepMine($channel, $section);

        return $this->done('Kept your version.');
    }

    public function regenerate(Request $request, Channel $channel, string $section, SummaryEditor $summaries): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);

        $summaries->regenerate($channel, $section, $request->user());

        return $this->done('Rewriting this section. It takes a minute or two; we\'ll message you when it\'s ready.');
    }

    private function authorizeChannel(Request $request, Channel $channel): void
    {
        $creator = $request->user()->creator;

        abort_unless($creator !== null && $channel->isClaimed() && $channel->creator_id === $creator->id, 403);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
