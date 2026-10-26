<?php

namespace App\Http\Controllers\Public;

use App\Enums\ChannelClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Services\Analytics\ViewTracker;
use App\Services\Profiles\ProfilePageBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /{slug}: registered last so it never shadows other routes (plan §4).
 * Creator profiles (the person and their channels) and channel short links
 * share this namespace.
 */
class CreatorProfileController extends Controller
{
    public function show(Request $request, string $slug, ProfilePageBuilder $builder, ViewTracker $views, ChannelPageController $channels): SymfonyResponse
    {
        $slug = strtolower($slug);
        $creator = Creator::where('slug', $slug)->with('user')->first();

        if ($creator === null) {
            $channel = Channel::where('slug', $slug)->where('claim_status', ChannelClaimStatus::Claimed)->first();

            abort_if($channel === null, 404);

            return $channels->page($request, $channel, $builder, $views);
        }

        $user = $request->user();
        $isOwner = $user !== null && $creator->user_id === $user->id;
        $isPublic = $creator->isPublished() && ! $creator->user?->isBanned();

        abort_unless($isPublic || $isOwner || $user?->isAdmin(), 404);

        if ($isPublic && ! $isOwner) {
            $views->record($creator, $request);
        }

        $profile = $builder->creator($creator, listedOnly: true);

        return Inertia::render('public/creator', [
            'profile' => $profile,
            'isOwner' => $isOwner,
            'isPreview' => ! $isPublic,
            'seo' => [
                'title' => $creator->display_name.' on Buildr',
                'description' => Str::limit(trim(($profile['hook'] ?? '').' '.($creator->bio ?? $profile['sections'][0]['intro'] ?? '')), 300),
                'canonical' => $creator->url(),
                'image' => $profile['og_image'],
                'noindex' => ! $isPublic,
                'jsonLd' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'ProfilePage',
                    'url' => $creator->url(),
                    'mainEntity' => array_filter([
                        '@type' => 'Person',
                        'name' => $creator->display_name,
                        'alternateName' => $creator->real_name,
                        'description' => $profile['hook'],
                        'image' => $profile['avatar'],
                        'sameAs' => array_merge(
                            array_column($profile['socials'], 'url'),
                            array_column($profile['channels'], 'telegram_url'),
                        ),
                    ]),
                ],
            ],
        ])->toResponse($request);
    }
}
