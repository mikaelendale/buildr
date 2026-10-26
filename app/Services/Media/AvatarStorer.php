<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Downloads avatars into our own storage (Telegram CDN URLs expire) and keeps
 * three square sizes: 400, 128 and 64 px.
 */
class AvatarStorer
{
    public const SIZES = [400, 128, 64];

    /**
     * Media is only ever fetched from Telegram's own hosts.
     */
    private const ALLOWED_HOSTS = ['telesco.pe', 'telegram.org', 't.me', 'telegram-cdn.org'];

    /**
     * @return string|null storage path of the 400px version
     */
    public function storeFromUrl(string $url, string $directory): ?string
    {
        if (! $this->isAllowed($url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->withUserAgent((string) config('buildr.scrape.user_agent'))->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        return $this->storeBinary($response->body(), $directory);
    }

    public function storeBinary(string $binary, string $directory): ?string
    {
        try {
            $manager = new ImageManager(new Driver);
            $version = substr(sha1($binary), 0, 10);
            $disk = Storage::disk('public');
            $mainPath = null;

            foreach (self::SIZES as $size) {
                $encoded = $manager->decodeBinary($binary)
                    ->cover($size, $size)
                    ->encode(new JpegEncoder(quality: 85, strip: true));

                $path = trim($directory, '/')."/{$version}-{$size}.jpg";
                $disk->put($path, (string) $encoded);
                $mainPath ??= $path;
            }

            return $mainPath;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Path of a smaller size next to the stored 400px avatar.
     */
    public static function sized(?string $path, int $size): ?string
    {
        if ($path === null) {
            return null;
        }

        return preg_replace('/-400\.jpg$/', "-{$size}.jpg", $path);
    }

    private function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || $scheme !== 'https') {
            return false;
        }

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
