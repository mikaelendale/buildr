<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\User;
use App\Services\Og\CardStyle;
use App\Services\Og\OgImageRenderer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shareable card (same PNG as the og:image) for copying or downloading.
 * Public for published pages; owners and admins can also fetch it while the
 * page is still a draft. `?theme=&color=` picks another look (rendered once,
 * and again whenever the owner's card is), `?fresh=1` re-renders it
 * (owners/admins only), `?download=1` sends it as an attachment.
 */
class ShareCardController extends Controller
{
    private const RENDER_KEY = 'share-card-looks';

    private const RENDERS_PER_MINUTE = 30;

    public function __invoke(Request $request, string $type, int $id, OgImageRenderer $renderer): StreamedResponse
    {
        abort_unless(in_array($type, ['creator', 'channel'], true), 404);

        $model = $type === 'creator' ? Creator::find($id) : Channel::find($id);
        abort_if($model === null, 404);

        /** @var User|null $user */
        $user = $request->user();
        $privileged = $user !== null && ($user->isAdmin() || $this->owns($user, $model));
        $public = $model instanceof Creator
            ? $model->isPublic()
            : $model->hasPublicPage();

        abort_unless($public || $privileged, 404);

        $disk = Storage::disk('public');
        $fresh = $privileged && $request->boolean('fresh');
        $default = OgImageRenderer::path($type, $id);
        $style = CardStyle::tryFrom($request->query('theme'), $request->query('color')) ?? CardStyle::for($model);
        $path = OgImageRenderer::pathFor($model, $style);

        // Rendering a new look costs real CPU; visitors share a site-wide
        // budget, and past it they get the owner's card until it frees up.
        if (! $privileged && $path !== $default && ! $this->fresh($disk, $path, $default)) {
            if (RateLimiter::tooManyAttempts(self::RENDER_KEY, self::RENDERS_PER_MINUTE)) {
                $path = $default;
            } else {
                RateLimiter::hit(self::RENDER_KEY, 60);
            }
        }

        if (! $disk->exists($default) || ($fresh && $path === $default)) {
            $this->render($renderer, $model);
        }

        if ($path !== $default && ($fresh || ! $this->fresh($disk, $path, $default))) {
            $this->render($renderer, $model, $style);
        }

        abort_unless($disk->exists($path), 404);

        $name = 'buildr-'.($model instanceof Creator ? $model->slug : $model->username).'.png';

        return $disk->response($path, $name, [
            'Content-Type' => 'image/png',
            'Cache-Control' => $public ? 'public, max-age=300' : 'private, no-store',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    /**
     * Another look is up to date until the owner's card is re-rendered (new
     * hook, stats, avatar).
     */
    private function fresh(Filesystem $disk, string $path, string $default): bool
    {
        return $disk->exists($path) && $disk->exists($default) && $disk->lastModified($path) >= $disk->lastModified($default);
    }

    private function render(OgImageRenderer $renderer, Creator|Channel $model, ?CardStyle $style = null): void
    {
        $model instanceof Creator ? $renderer->renderForCreator($model, $style) : $renderer->renderForChannel($model, $style);
    }

    private function owns(User $user, Creator|Channel $model): bool
    {
        $creator = $model instanceof Creator ? $model : $model->creator;

        return $creator !== null && $creator->user_id === $user->id;
    }
}
