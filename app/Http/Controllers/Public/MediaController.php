<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avatars from our own origin. The profile cover prints the avatar through a
 * WebGL shader, and WebGL refuses cross-origin images without CORS headers,
 * which the bucket's public URL doesn't send. File names carry a content
 * hash, so the response can be cached for good.
 */
class MediaController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
