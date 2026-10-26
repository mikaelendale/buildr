<?php

namespace App\Http\Controllers\Public;

use App\Enums\ChannelVisibility;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\Analytics\ViewTracker;
use App\Services\Profiles\ProfilePageBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Every channel has its own page (plan §9.5). GET /c/{username} always
 * works; a claimed channel with a short link 301s to /{slug}, which the
 * profile catch-all serves through page().
 */
class ChannelPageController extends Controller
{
    public function show(Request $request, string $username, ProfilePageBuilder $builder, ViewTracker $views): SymfonyResponse
    {
        $channel = Channel::where('username', strtolower($username))->with('creator.user')->first();

        abort_if($channel === null, 404);

        if ($channel->visibility === ChannelVisibility::Removed) {
            return Inertia::render('public/removed', ['username' => $channel->username])
                ->toResponse($request)
                ->setStatusCode(410);
        }

        if ($channel->slug !== null && $channel->isClaimed() && $channel->hasPublicPage()) {
            return redirect()->to($channel->publicUrl(), 301);
        }

        return $this->page($request, $channel, $builder, $views);
    }

    public function page(Request $request, Channel $channel, ProfilePageBuilder $builder, ViewTracker $views): SymfonyResponse
    {
        $channel->loadMissing('creator.user');

        $user = $request->user();
        $isOwner = $user !== null && $channel->isClaimed() && $channel->creator?->user_id === $user->id;
        $isPublic = $channel->hasPublicPage();

        abort_unless($isPublic || $isOwner || $user?->isAdmin(), 404);

        if ($isPublic && ! $isOwner) {
            $views->record($channel, $request);
        }

        $page = $builder->channel($channel);

        return Inertia::render('public/channel', [
            'channel' => $page,
            'isOwner' => $isOwner,
            'isPreview' => ! $isPublic,
            'seo' => [
                'title' => $channel->displayTitle()." (@{$channel->username})",
                'description' => Str::limit(trim(($page['hook'] ?? '').' '.($page['sections'][0]['intro'] ?? $channel->description ?? '')), 300),
                'canonical' => $channel->publicUrl(),
                'image' => $page['og_image'],
                'noindex' => ! $isPublic,
                'jsonLd' => $channel->isClaimed() ? [
                    '@context' => 'https://schema.org',
                    '@type' => 'ProfilePage',
                    'url' => $channel->publicUrl(),
                    'mainEntity' => array_filter([
                        '@type' => 'CreativeWorkSeries',
                        'name' => $channel->displayTitle(),
                        'description' => $page['hook'],
                        'image' => $page['avatar'],
                        'sameAs' => [$channel->telegramUrl()],
                        'author' => $page['owner'] !== null ? [
                            '@type' => 'Person',
                            'name' => $page['owner']['name'],
                            'url' => $page['owner']['url'],
                        ] : null,
                    ]),
                ] : null,
            ],
        ])->toResponse($request);
    }
}
