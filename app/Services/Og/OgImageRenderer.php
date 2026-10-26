<?php

namespace App\Services\Og;

use App\Models\Channel;
use App\Models\Creator;
use App\Services\Profiles\SummaryPresenter;
use App\Support\LanguageDetector;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use RuntimeException;
use Throwable;

/**
 * 1200×630 share card per profile/channel (plan §11), also the og:image.
 * A white card on the warm canvas: on the left the person's avatar as an
 * ordered-dither (Bayer 8×8) halftone in their tone, the same identity the
 * profile cover uses; on the right name, handle, hook, a few real stats and
 * the profile URL. Stored on the public disk, regenerated on publish /
 * summary change, rendered on demand when missing.
 */
class OgImageRenderer
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    private const BG = '#f7f7f5';

    private const CARD = '#ffffff';

    private const LINE = '#e6e6e2';

    private const ACCENT = '#8eeed2';

    private const TEXT = '#1c1c1a';

    private const MUTED = '#7c7c76';

    /** Same order as TONES in resources/js/components/figures/dither-field.tsx. */
    private const TONES = ['mint', 'sky', 'violet', 'amber', 'rose'];

    /** [paper, highlight, ink] per tone, matching the profile cover. */
    private const PALETTES = [
        'mint' => ['#e9f8f2', '#8fe3c9', '#1f9a7a'],
        'sky' => ['#ebf2fd', '#a9cbf8', '#2f6fd6'],
        'violet' => ['#f1ecfd', '#c6b5f8', '#6a4bdc'],
        'amber' => ['#fdf4e3', '#f3cf86', '#b26b0e'],
        'rose' => ['#fdeef2', '#f6b3c6', '#c2335f'],
    ];

    private const BAYER = [
        [0, 32, 8, 40, 2, 34, 10, 42],
        [48, 16, 56, 24, 50, 18, 58, 26],
        [12, 44, 4, 36, 14, 46, 6, 38],
        [60, 28, 52, 20, 62, 30, 54, 22],
        [3, 35, 11, 43, 1, 33, 9, 41],
        [51, 19, 59, 27, 49, 17, 57, 25],
        [15, 47, 7, 39, 13, 45, 5, 37],
        [63, 31, 55, 23, 61, 29, 53, 21],
    ];

    // Portrait: a 112×125 grid of dither cells, each 4px (448×500).
    private const GRID_W = 112;

    private const GRID_H = 125;

    private const CELL = 4;

    public function __construct(private readonly SummaryPresenter $presenter) {}

    public static function path(string $type, int $id): string
    {
        return "og/{$type}/{$id}.png";
    }

    /**
     * The profile's tone, computed exactly like seedFrom() + TONES on the
     * frontend so the card matches the cover.
     */
    public static function tone(string $seedKey): string
    {
        $hash = 0;

        foreach (mb_str_split($seedKey) as $char) {
            $hash = ($hash * 31 + mb_ord($char)) & 0xFFFFFFFF;

            if ($hash & 0x80000000) {
                $hash -= 0x100000000;
            }
        }

        return self::TONES[abs($hash) % count(self::TONES)];
    }

    /**
     * Every channel has its own card; a claimed one gets the verified badge.
     */
    public function renderForChannel(Channel $channel): ?string
    {
        $owner = $channel->showsOwner() ? $channel->creator : null;

        return $this->render(
            self::path('channel', $channel->id),
            $channel->displayTitle(),
            Str::limit('@'.$channel->username, 38),
            $this->presenter->hook($channel) ?? $channel->description,
            $this->facts($channel, $channel->subscribers),
            $channel->avatar_path ?? $owner?->avatar_path,
            self::tone($channel->username),
            $channel->publicUrl(),
            $channel->isClaimed(),
        );
    }

    /**
     * After a channel's content changes: its own card, and its owner's (the
     * profile card leads with the main channel's hook and numbers).
     */
    public function refreshForChannel(Channel $channel): void
    {
        $this->renderForChannel($channel);

        if ($channel->isClaimed() && $channel->creator !== null) {
            $this->renderForCreator($channel->creator);
        }
    }

    public function renderForCreator(Creator $creator): ?string
    {
        $header = $creator->headerChannel();

        return $this->render(
            self::path('creator', $creator->id),
            $creator->display_name,
            $header !== null ? '@'.$header->username : null,
            ($header !== null ? $this->presenter->hook($header) : null) ?? $creator->bio ?? $header?->description,
            $this->facts($header, (int) $creator->channels->sum('subscribers') ?: null),
            $creator->avatar_path ?? $header?->avatar_path,
            self::tone($creator->slug),
            url('/'.$creator->slug),
            true,
        );
    }

    /**
     * Up to three real numbers from channel_stats, most telling first.
     *
     * @return array<int, array{0: string, 1: string}> [value, label]
     */
    private function facts(?Channel $channel, ?int $subscribers): array
    {
        $stats = $channel?->stats->stats ?? [];
        $facts = [];

        if ($subscribers) {
            $facts[] = [(string) Number::abbreviate($subscribers, maxPrecision: 1), 'subscribers'];
        }

        $perWeek = is_numeric($stats['posts_per_week'] ?? null) ? (float) $stats['posts_per_week'] : 0.0;

        if ($perWeek > 0) {
            $facts[] = [$perWeek >= 10 ? (string) round($perWeek) : (string) round($perWeek, 1), 'posts a week'];
        }

        $current = (int) ($stats['current_streak_weeks'] ?? 0);
        $longest = (int) ($stats['longest_streak_weeks'] ?? 0);

        if ($current > 1) {
            $facts[] = ["{$current} wk", 'posting streak'];
        } elseif ($longest > 1) {
            $facts[] = ["{$longest} wk", 'best streak'];
        }

        $shipped = (int) ($stats['projects_shipped'] ?? 0);

        if ($shipped > 0) {
            $facts[] = [(string) $shipped, $shipped === 1 ? 'project shipped' : 'projects shipped'];
        }

        return array_slice($facts, 0, 3);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $facts
     */
    public function render(string $path, string $name, ?string $handle, ?string $hook, array $facts, ?string $avatarPath, string $tone, string $url, bool $verified): ?string
    {
        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->decodeBinary($this->background());

            $palette = self::PALETTES[$tone] ?? self::PALETTES['mint'];
            $avatar = $avatarPath !== null && Storage::disk('public')->exists($avatarPath)
                ? (string) Storage::disk('public')->get($avatarPath)
                : null;

            $image->insert($manager->decodeBinary($this->portrait($avatar, $palette, $tone)), 72, 65);

            $x = 584;
            $ink = $palette[2];

            // Brand row: the logo tile + wordmark.
            $image->insert($manager->decodeBinary($this->logoMark(40)), $x, 86);
            $this->text($image, (string) config('app.name', 'Buildr'), $x + 52, 116, 26, bold: true);

            // Name, shrunk to fit the column, then the verified badge in their tone.
            $name = Str::limit($this->clean($name), 22);
            $badge = $verified ? 38 + 12 : 0;
            $size = 54;

            while ($size > 38 && $this->textWidth($name, $size, bold: true) + $badge > 1128 - $x) {
                $size -= 2;
            }

            $this->text($image, $name, $x, 206, $size, bold: true);

            if ($verified) {
                $image->insert($manager->decodeBinary($this->badge(38, $ink)), $x + $this->textWidth($name, $size, bold: true) + 12, 206 - (int) round($size * 0.72) - 1);
            }

            if ($handle !== null) {
                $this->text($image, $handle, $x, 246, 26, color: self::MUTED);
            }

            if ($hook !== null && trim($hook) !== '') {
                $this->text($image, Str::limit($this->clean($hook), 120), $x, 276, 29, wrap: 540, lineHeight: 1.55, valign: 'top');
            }

            foreach ($facts as $i => [$value, $label]) {
                $fx = $x + $i * 186;
                $this->text($image, $value, $fx, 476, 36, bold: true);
                $this->text($image, $label, $fx, 506, 20, color: self::MUTED);
            }

            $this->text($image, $this->displayUrl($url), $x, 562, 24, bold: true, color: $ink);

            Storage::disk('public')->put($path, (string) $image->encode(new PngEncoder));

            return $path;
        } catch (Throwable $e) {
            Log::channel('buildr')->warning("OG render failed for {$path}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Canvas + rounded white card, drawn at 2× and downsampled so the
     * corners are smooth (GD shapes are not antialiased).
     */
    private function background(): string
    {
        $scale = 2;
        $big = $this->canvas(self::WIDTH * $scale, self::HEIGHT * $scale, self::BG);
        $this->roundedRect($big, 32 * $scale, 32 * $scale, (self::WIDTH - 64) * $scale, (self::HEIGHT - 64) * $scale, 34 * $scale, self::LINE);
        $this->roundedRect($big, 34 * $scale, 34 * $scale, (self::WIDTH - 68) * $scale, (self::HEIGHT - 68) * $scale, 32 * $scale, self::CARD);

        $out = $this->canvas(self::WIDTH, self::HEIGHT, self::BG);
        imagecopyresampled($out, $big, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * $scale, self::HEIGHT * $scale);

        return $this->png($out);
    }

    /**
     * The halftone portrait: avatar → luminance (contrast-stretched) →
     * Bayer 8×8 into three inks (paper, highlight, ink) → 5px cells, with
     * the dark parts printed so it reads like ink on paper. No avatar: a
     * seeded wave field in the same inks.
     *
     * @param  array{0: string, 1: string, 2: string}  $palette
     */
    private function portrait(?string $avatar, array $palette, string $tone): string
    {
        $w = self::GRID_W;
        $h = self::GRID_H;
        $levels = $this->luminance($avatar, $w, $h)
            ?? $this->field($w, $h, crc32($tone));

        $inks = array_map(fn (string $hex) => $this->rgb($hex), $palette);
        $out = $this->newImage($w * self::CELL, $h * self::CELL);

        imagesavealpha($out, true);
        $colors = array_map(fn (array $c) => (int) imagecolorallocate($out, $c[0], $c[1], $c[2]), $inks);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                // Darkness 0..1 spread over three inks with an ordered threshold.
                $scaled = (1 - $levels[$y][$x]) * 2;
                $base = (int) floor($scaled);
                $step = $base + (($scaled - $base) > (self::BAYER[$y % 8][$x % 8] + 0.5) / 64 ? 1 : 0);
                $color = $colors[min(2, max(0, $step))];

                imagefilledrectangle($out, $x * self::CELL, $y * self::CELL, ($x + 1) * self::CELL - 1, ($y + 1) * self::CELL - 1, $color);
            }
        }

        $this->roundCorners($out, 26);

        return $this->png($out);
    }

    /**
     * Downscale (cover crop) to the grid and return contrast-stretched
     * luminance 0..1 per cell, or null when the avatar can't be decoded.
     *
     * @return list<list<float>>|null
     */
    private function luminance(?string $binary, int $w, int $h): ?array
    {
        if ($binary === null) {
            return null;
        }

        $src = @imagecreatefromstring($binary);

        if (! $src instanceof GdImage) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = $w / $h;
        [$cw, $ch] = $sw / $sh > $ratio ? [(int) round($sh * $ratio), $sh] : [$sw, (int) round($sw / $ratio)];

        $small = $this->newImage($w, $h);

        imagecopyresampled($small, $src, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $w, $h, $cw, $ch);

        $values = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $values[$y][$x] = (0.2126 * (($rgb >> 16) & 0xFF) + 0.7152 * (($rgb >> 8) & 0xFF) + 0.0722 * ($rgb & 0xFF)) / 255;
            }
        }

        // Stretch between the 3rd and 97th percentile (ignores blown highlights
        // and pure blacks), then deepen mid-tones so faces take ink.
        $sorted = array_merge(...$values);
        sort($sorted);
        $min = $sorted[(int) floor(count($sorted) * 0.03)];
        $max = $sorted[(int) floor(count($sorted) * 0.97)];
        $range = max(0.05, $max - $min);

        return array_values(array_map(
            fn (array $row) => array_values(array_map(fn (float $l) => max(0.0, min(1.0, ($l - $min) / $range)) ** 1.15, $row)),
            $values,
        ));
    }

    /**
     * Seeded diagonal wave field for profiles without an avatar.
     *
     * @return list<list<float>>
     */
    private function field(int $w, int $h, int $seed): array
    {
        $angle = ($seed % 360) * M_PI / 180;
        $values = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $u = $x * cos($angle) + $y * sin($angle);
                $v = sin($u / 7 + ($seed % 13)) * 0.5 + sin(($x - $y) / 11) * 0.3;
                $values[$y][$x] = max(0.0, min(1.0, 0.55 + $v * 0.45));
            }
        }

        return array_values(array_map('array_values', $values));
    }

    /**
     * The site's logo tile (resources/js/components/brand/logo.tsx), drawn at
     * 4x and downsampled: white rounded tile, ink "b", one mint dot.
     */
    private function logoMark(int $size): string
    {
        $k = $size * 4 / 32;
        $tile = (int) round(32 * $k);
        $img = $this->transparent($tile);

        $this->roundedRect($img, 0, 0, $tile, $tile, (int) round(9.5 * $k), self::LINE);
        $this->roundedRect($img, (int) round($k), (int) round($k), (int) round(30 * $k), (int) round(30 * $k), (int) round(8.5 * $k), self::CARD);

        // Stem of the "b" with round caps.
        $half = 1.3 * $k;
        $this->disc($img, 11.2 * $k, 7.8 * $k, $half, self::TEXT);
        $this->disc($img, 11.2 * $k, 24.2 * $k, $half, self::TEXT);
        imagefilledrectangle($img, (int) round(11.2 * $k - $half), (int) round(7.8 * $k), (int) round(11.2 * $k + $half), (int) round(24.2 * $k), $this->color($img, self::TEXT));

        // Bowl: an ink disc with a white disc punched in.
        $this->disc($img, 16.9 * $k, 18.6 * $k, 6.7 * $k, self::TEXT);
        $this->disc($img, 16.9 * $k, 18.6 * $k, 4.1 * $k, self::CARD);

        $this->disc($img, 23.6 * $k, 8.6 * $k, 2.3 * $k, self::ACCENT);

        return $this->png($this->downsample($img, $size));
    }

    /**
     * A scalloped "verified" rosette with a white check, in the profile's ink.
     */
    private function badge(int $size, string $ink): string
    {
        $big = $size * 4;
        $img = $this->transparent($big);
        $c = $big / 2;

        for ($i = 0; $i < 12; $i++) {
            $a = $i * M_PI / 6;
            $this->disc($img, $c + cos($a) * $big * 0.3, $c + sin($a) * $big * 0.3, $big * 0.18, $ink);
        }

        $this->disc($img, $c, $c, $big * 0.36, $ink);

        $white = $this->color($img, '#ffffff');
        imagesetthickness($img, (int) round($big * 0.085));
        imageline($img, (int) round($big * 0.32), (int) round($big * 0.52), (int) round($big * 0.45), (int) round($big * 0.65), $white);
        imageline($img, (int) round($big * 0.45), (int) round($big * 0.65), (int) round($big * 0.69), (int) round($big * 0.38), $white);

        return $this->png($this->downsample($img, $size));
    }

    private function transparent(int $size): GdImage
    {
        $img = $this->newImage($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagealphablending($img, true);

        return $img;
    }

    private function downsample(GdImage $img, int $size): GdImage
    {
        $out = $this->transparent($size);
        imagealphablending($out, false);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, imagesx($img), imagesy($img));

        return $out;
    }

    private function disc(GdImage $img, float $cx, float $cy, float $r, string $hex): void
    {
        imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $this->color($img, $hex));
    }

    private function color(GdImage $img, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocate($img, $r, $g, $b);
    }

    private function textWidth(string $text, int $size, bool $bold = false): int
    {
        $box = imagettfbbox($size * .76, 0, $this->fontFile($text, $bold), $text);

        return $box === false ? 0 : (int) abs($box[2] - $box[0]);
    }

    private function fontFile(string $text, bool $bold): string
    {
        return resource_path('fonts/'.(LanguageDetector::hasEthiopic($text) ? 'NotoSansEthiopic' : 'NotoSans').($bold ? '-Bold' : '-Regular').'.ttf');
    }

    private function canvas(int $w, int $h, string $hex): GdImage
    {
        $img = $this->newImage($w, $h);

        [$r, $g, $b] = $this->rgb($hex);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, $r, $g, $b));

        return $img;
    }

    private function roundedRect(GdImage $img, int $x, int $y, int $w, int $h, int $r, string $hex): void
    {
        [$red, $green, $blue] = $this->rgb($hex);
        $color = (int) imagecolorallocate($img, $red, $green, $blue);
        $r = min($r, intdiv($w, 2), intdiv($h, 2));

        imagefilledrectangle($img, $x + $r, $y, $x + $w - $r - 1, $y + $h - 1, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w - 1, $y + $h - $r - 1, $color);

        foreach ([[$x + $r, $y + $r], [$x + $w - $r - 1, $y + $r], [$x + $r, $y + $h - $r - 1], [$x + $w - $r - 1, $y + $h - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    /**
     * Clear pixels outside a corner radius (hard edge; it suits the pixel art).
     */
    private function roundCorners(GdImage $img, int $r): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        imagealphablending($img, false);
        $clear = (int) imagecolorallocatealpha($img, 255, 255, 255, 127);

        foreach ([[$r, $r], [$w - $r - 1, $r], [$r, $h - $r - 1], [$w - $r - 1, $h - $r - 1]] as [$cx, $cy]) {
            $xs = $cx < $w / 2 ? range(0, $r) : range($w - $r - 1, $w - 1);
            $ys = $cy < $h / 2 ? range(0, $r) : range($h - $r - 1, $h - 1);

            foreach ($ys as $py) {
                foreach ($xs as $px) {
                    if (($px - $cx) ** 2 + ($py - $cy) ** 2 > $r ** 2) {
                        imagesetpixel($img, $px, $py, $clear);
                    }
                }
            }
        }
    }

    /**
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $channel = fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return [$channel(0), $channel(2), $channel(4)];
    }

    private function newImage(int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor(max(1, $w), max(1, $h));

        if (! $img instanceof GdImage) {
            throw new RuntimeException('Could not allocate an image.');
        }

        return $img;
    }

    private function png(GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function displayUrl(string $url): string
    {
        return (string) preg_replace('~^https?://(www\.)?~', '', rtrim($url, '/'));
    }

    private function text(
        ImageInterface $image,
        string $text,
        int $x,
        int $y,
        int $size,
        bool $bold = false,
        string $color = self::TEXT,
        string $align = 'left',
        ?int $wrap = null,
        float $lineHeight = 1.2,
        string $valign = 'bottom',
    ): void {
        $file = $this->fontFile($text, $bold);

        $image->text($text, $x, $y, function (FontFactory $font) use ($file, $size, $color, $align, $valign, $wrap, $lineHeight) {
            $font->filename($file);
            $font->size($size);
            $font->color($color);
            $font->align($align, $valign);
            $font->lineHeight($lineHeight);

            if ($wrap !== null) {
                $font->wrap($wrap);
            }
        });
    }

    /**
     * GD cannot draw color emoji; drop them so they don't render as boxes.
     */
    private function clean(string $text): string
    {
        // Pictographs, flags (regional indicators), tag sequences, joiners.
        $text = preg_replace('/[\p{Extended_Pictographic}\x{1F1E6}-\x{1F1FF}\x{E0020}-\x{E007F}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
