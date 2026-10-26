<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\ChannelSuggestion;
use App\Support\TelegramUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Suggest a channel" from the empty search state: creates an admin task,
 * never an automatic scrape (plan §9.2).
 */
class ChannelSuggestionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:200']]);

        $username = TelegramUsername::normalize($request->string('username')->toString());

        if ($username === null) {
            throw ValidationException::withMessages(['username' => 'Enter a channel @handle or t.me link.']);
        }

        $known = Channel::where('username', $username)->exists() || BlockedUsername::isBlocked($username);

        if (! $known && ! ChannelSuggestion::where('username', $username)->where('status', 'open')->exists()) {
            ChannelSuggestion::create([
                'username' => $username,
                'user_id' => $request->user()?->id,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Thanks! We'll take a look at @{$username}."]);

        return back();
    }
}
