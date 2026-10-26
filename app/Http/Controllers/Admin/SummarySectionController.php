<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentSource;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Creators\SummaryEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin "Edit a section" / "Hide a section" (plan §10.2, §10.6). Stored as
 * admin overrides on the channel, so they also work on unclaimed pages.
 */
class SummarySectionController extends Controller
{
    public function update(Request $request, Channel $channel, string $section, SummaryEditor $editor): RedirectResponse
    {
        $request->validate(['value' => ['present']]);
        $editor->edit($channel, $section, $request->input('value'), $request->user(), ContentSource::Admin);

        return $this->done('Section saved.');
    }

    public function hide(Request $request, Channel $channel, string $section, SummaryEditor $editor): RedirectResponse
    {
        $editor->hide($channel, $section, $request->user(), $request->boolean('hidden', true), ContentSource::Admin);

        return $this->done('Section visibility updated.');
    }

    public function reset(Request $request, Channel $channel, string $section, SummaryEditor $editor): RedirectResponse
    {
        $editor->reset($channel, $section, $request->user());

        return $this->done('Back to the AI version.');
    }

    public function keep(Request $request, Channel $channel, string $section, SummaryEditor $editor): RedirectResponse
    {
        $editor->keepMine($channel, $section);

        return $this->done('Kept the edited version.');
    }

    public function regenerate(Request $request, Channel $channel, string $section, SummaryEditor $editor): RedirectResponse
    {
        $editor->regenerate($channel, $section, $request->user());

        return $this->done('Regenerating this section.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
