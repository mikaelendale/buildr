<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\Tag;
use App\Services\Og\OgImageRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * sitemap.xml, robots.txt and OG images (plan §11).
 */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addDay(), function () {
            $urls = [
                $this->urlTag(url('/'), null, '1.0'),
                $this->urlTag(url('/discover'), null, '0.8'),
                $this->urlTag(url('/about'), null, '0.3'),
                $this->urlTag(url('/claim'), null, '0.5'),
            ];

            DirectoryEntry::visible()->orderBy('id')->each(function (DirectoryEntry $entry) use (&$urls) {
                $urls[] = $this->urlTag(url($entry->url_path), $entry->updated_at?->toDateString(), '0.7');
            });

            // Creator profiles: the person, linking out to each channel page.
            Creator::published()->whereHas('user', fn ($q) => $q->whereNull('banned_at'))->orderBy('id')->each(function (Creator $creator) use (&$urls) {
                $urls[] = $this->urlTag($creator->url(), $creator->updated_at?->toDateString(), '0.6');
            });

            Tag::curated()->get()->each(function (Tag $tag) use (&$urls) {
                $urls[] = $this->urlTag(url('/tags/'.$tag->slug), null, '0.5');
            });

            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function urlTag(string $loc, ?string $lastmod, string $priority): string
    {
        return '<url><loc>'.e($loc).'</loc>'
            .($lastmod !== null ? '<lastmod>'.$lastmod.'</lastmod>' : '')
            .'<priority>'.$priority.'</priority></url>';
    }

    public function robots(): Response
    {
        $lines = app()->isProduction()
            ? ['User-agent: *', 'Disallow: /admin', 'Disallow: /dashboard', 'Disallow: /onboarding', 'Disallow: /api', '', 'Sitemap: '.url('/sitemap.xml')]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }

    public function og(string $type, int $id, OgImageRenderer $renderer): BinaryFileResponse|StreamedResponse
    {
        abort_unless(in_array($type, ['creator', 'channel'], true), 404);

        $path = OgImageRenderer::path($type, $id);
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $model = $type === 'creator' ? Creator::published()->find($id) : Channel::find($id);

            abort_if($model === null || ($model instanceof Channel && ! $model->hasPublicPage()), 404);

            $model instanceof Creator ? $renderer->renderForCreator($model) : $renderer->renderForChannel($model);
        }

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
