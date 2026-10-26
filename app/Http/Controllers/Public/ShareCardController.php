<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\User;
use App\Services\Og\OgImageRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shareable card (same PNG as the og:image) for copying or downloading.
 * Public for published pages; owners and admins can also fetch it while the
 * page is still a draft. `?fresh=1` re-renders it (owners/admins only),
 * `?download=1` sends it as an attachment.
 */
class ShareCardController extends Controller
{
    public function __invoke(Request $request, string $type, int $id, OgImageRenderer $renderer): StreamedResponse
    {
        abort_unless(in_array($type, ['creator', 'channel'], true), 404);

        $model = $type === 'creator' ? Creator::find($id) : Channel::find($id);
        abort_if($model === null, 404);

        /** @var User|null $user */
        $user = $request->user();
        $privileged = $user !== null && ($user->isAdmin() || $this->owns($user, $model));
        $public = $model instanceof Creator
            ? $model->isPublished()
            : $model->hasPublicPage();

        abort_unless($public || $privileged, 404);

        $path = OgImageRenderer::path($type, $id);
        $disk = Storage::disk('public');

        if (! $disk->exists($path) || ($privileged && $request->boolean('fresh'))) {
            $model instanceof Creator ? $renderer->renderForCreator($model) : $renderer->renderForChannel($model);
        }

        abort_unless($disk->exists($path), 404);

        $name = 'buildr-'.($model instanceof Creator ? $model->slug : $model->username).'.png';

        return $disk->response($path, $name, [
            'Content-Type' => 'image/png',
            'Cache-Control' => $public ? 'public, max-age=300' : 'private, no-store',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    private function owns(User $user, Creator|Channel $model): bool
    {
        $creator = $model instanceof Creator ? $model : $model->creator;

        return $creator !== null && $creator->user_id === $user->id;
    }
}
