<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Notifications\Announcer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/announcements: tell creators about something new, through the
 * bot. Plain text (escaped), an optional link button, a test copy to one
 * connected account first, then everyone who connected and kept updates
 * on. Each send is logged.
 */
class AnnouncementController extends Controller
{
    public function index(Announcer $announcer): Response
    {
        return Inertia::render('admin/announcements', [
            'counts' => $announcer->counts(),
            'footer' => Announcer::FOOTER,
            'history' => Announcement::with('user')->latest()->limit(30)->get()->map(fn (Announcement $a) => [
                'id' => $a->id,
                // Stored as sent (Telegram HTML); shown as plain text.
                'body' => html_entity_decode(strip_tags($a->body), ENT_QUOTES | ENT_HTML5),
                'button_label' => $a->button_label,
                'button_url' => $a->button_url,
                'recipients' => $a->recipients,
                'by' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, Announcer $announcer): RedirectResponse
    {
        $data = $this->validated($request);
        $announcement = $announcer->send(Announcer::fromText($data['body']), $data['button_label'] ?? null, $data['button_url'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $announcement->recipients === 1
            ? 'Sent to 1 creator.'
            : "Sent to {$announcement->recipients} creators."]);

        return back();
    }

    /**
     * A single copy to a connected account (by Telegram username).
     */
    public function test(Request $request, Announcer $announcer): RedirectResponse
    {
        $data = $this->validated($request, ['to' => ['required', 'string', 'max:64']]);
        $username = ltrim(trim($data['to']), '@');

        $to = User::whereRaw('lower(telegram_username) = ?', [strtolower($username)])->whereNotNull('telegram_id')->first();

        if ($to === null || ! $announcer->test(Announcer::fromText($data['body']), $data['button_label'] ?? null, $data['button_url'] ?? null, $to)) {
            throw ValidationException::withMessages(['to' => "@{$username} hasn't connected the bot."]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Test sent to @{$username}."]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extra = []): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'max:3000'],
            'button_label' => ['nullable', 'required_with:button_url', 'string', 'max:40'],
            'button_url' => ['nullable', 'required_with:button_label', 'url:https', 'max:300'],
        ] + $extra, [
            'button_url.url' => 'Use a full https:// link.',
        ]);
    }
}
