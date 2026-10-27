<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Services\Og\CardStyle;
use App\Services\Og\OgImageRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The owner's share card look (light, dark or solid color, and the color
 * it prints in): saved on the profile or channel, it becomes the link
 * preview and sets the tone of the page cover.
 */
class CardStyleController extends Controller
{
    public function __invoke(Request $request, string $type, int $id, OgImageRenderer $og): RedirectResponse
    {
        abort_unless(in_array($type, ['creator', 'channel'], true), 404);

        $data = $request->validate([
            'theme' => ['required', Rule::in(CardStyle::THEMES)],
            'color' => ['required', Rule::in(CardStyle::COLORS)],
        ]);

        $creator = $request->user()->creator;
        $model = $type === 'creator'
            ? ($creator?->id === $id ? $creator : null)
            : Channel::find($id);

        abort_unless($creator !== null && $model !== null && ($model instanceof Creator || ($model->isClaimed() && $model->creator_id === $creator->id)), 403);

        $model->forceFill(['card_theme' => $data['theme'], 'card_color' => $data['color']])->save();

        $model instanceof Creator ? $og->renderForCreator($model) : $og->renderForChannel($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Link previews show this card now.']);

        return back();
    }
}
