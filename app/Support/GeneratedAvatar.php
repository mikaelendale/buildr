<?php

namespace App\Support;

use GdImage;

/**
 * Deterministic, designed avatars for demo and placeholder profiles, in the
 * site palette. "app" draws an app-icon (shape + initial) for channels;
 * "face" draws a small character for people. Drawn at 4× and downsampled,
 * since GD has no anti-aliasing for filled shapes.
 */
final class GeneratedAvatar
{
    /** [soft background, accent, ink] per tone. */
    private const PALETTES = [
        'mint' => ['#d6f5ea', '#22a383', '#1c1c1a'],
        'sky' => ['#dbe9fd', '#3b7de2', '#1c1c1a'],
        'violet' => ['#e8e0fd', '#7a5be4', '#1c1c1a'],
        'amber' => ['#fcefd2', '#d4891c', '#1c1c1a'],
        'rose' => ['#fde1e9', '#d33f6d', '#1c1c1a'],
    ];

    private const SCALE = 4;

    public static function png(string $seed, string $style = 'app', ?string $label = null, int $size = 256): string
    {
        $hash = crc32(mb_strtolower($seed));
        $tones = array_keys(self::PALETTES);
        [$soft, $accent, $ink] = self::PALETTES[$tones[$hash % count($tones)]];

        $size = max(16, $size);
        $big = $size * self::SCALE;
        $img = imagecreatetruecolor($big, $big);
        imagefill($img, 0, 0, self::color($img, $soft));

        $style === 'face'
            ? self::face($img, $big, $hash, $accent, $ink)
            : self::app($img, $big, $hash, $accent, $ink, $label ?? $seed);

        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);

        ob_start();
        imagepng($out, null, 6);

        return (string) ob_get_clean();
    }

    private static function app(GdImage $img, int $s, int $hash, string $accent, string $ink, string $label): void
    {
        $accentColor = self::color($img, $accent);
        $inkColor = self::color($img, $ink);
        $white = self::color($img, '#ffffff');

        // A large accent disc or squircle, nudged off-centre, and a small ink orbit dot.
        $offset = (($hash >> 3) % 3 - 1) * $s / 14;
        $disc = (int) ($s * 0.66);
        $x = (int) ($s / 2 + $offset);
        $y = (int) ($s / 2 - $offset / 2);

        if (($hash >> 9) % 2 === 0) {
            imagefilledellipse($img, $x, $y, $disc, $disc, $accentColor);
        } else {
            $half = (int) ($disc / 2);
            $r = (int) ($disc * 0.28);
            imagefilledrectangle($img, $x - $half + $r, $y - $half, $x + $half - $r, $y + $half, $accentColor);
            imagefilledrectangle($img, $x - $half, $y - $half + $r, $x + $half, $y + $half - $r, $accentColor);

            foreach ([[-1, -1], [1, -1], [-1, 1], [1, 1]] as [$dx, $dy]) {
                imagefilledellipse($img, $x + $dx * ($half - $r), $y + $dy * ($half - $r), $r * 2, $r * 2, $accentColor);
            }
        }

        $angle = deg2rad(($hash >> 5) % 360);
        $orbit = $s * 0.36;
        imagefilledellipse($img, (int) ($s / 2 + cos($angle) * $orbit), (int) ($s / 2 + sin($angle) * $orbit), (int) ($s * 0.13), (int) ($s * 0.13), $inkColor);

        // The initial, white on the disc.
        $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $label) ?: '?', 0, 1));
        $font = resource_path('fonts/NotoSans-Bold.ttf');
        $fontSize = $s * 0.24;
        $box = imagettfbbox($fontSize, 0, $font, $initial) ?: [0, 0, 0, 0, 0, 0, 0, 0];
        $width = $box[2] - $box[0];
        $height = $box[1] - $box[7];
        imagettftext($img, $fontSize, 0, (int) ($s / 2 + $offset - $width / 2 - $box[0]), (int) ($s / 2 - $offset / 2 + $height / 2), $white, $font, $initial);
    }

    private static function face(GdImage $img, int $s, int $hash, string $accent, string $ink): void
    {
        $accentColor = self::color($img, $accent);
        $inkColor = self::color($img, $ink);
        $white = self::color($img, '#ffffff');

        // Head: a big rounded shape that leans a little to one side.
        $lean = (($hash >> 2) % 5 - 2) * $s / 40;
        $head = (int) ($s * 0.74);
        $cx = (int) ($s / 2 + $lean);
        $cy = (int) ($s * 0.56);
        imagefilledellipse($img, $cx, $cy, $head, (int) ($head * 1.04), $accentColor);

        // Hair: a band across the top of the head in ink, shape picked by hash.
        $hair = ($hash >> 7) % 3;
        if ($hair === 0) {
            imagefilledarc($img, $cx, $cy - (int) ($head * 0.08), $head, (int) ($head * 0.9), 180, 360, $inkColor, IMG_ARC_PIE);
        } elseif ($hair === 1) {
            imagefilledellipse($img, $cx - (int) ($head * 0.18), $cy - (int) ($head * 0.36), (int) ($head * 0.55), (int) ($head * 0.4), $inkColor);
            imagefilledellipse($img, $cx + (int) ($head * 0.16), $cy - (int) ($head * 0.4), (int) ($head * 0.5), (int) ($head * 0.34), $inkColor);
        } else {
            imagefilledarc($img, $cx, $cy - (int) ($head * 0.02), (int) ($head * 1.04), $head, 200, 340, $inkColor, IMG_ARC_PIE);
        }

        // Eyes and a smile.
        $eyeY = $cy + (int) ($head * 0.04);
        $gap = (int) ($head * 0.17);
        $eye = (int) ($s * 0.05);
        imagefilledellipse($img, $cx - $gap, $eyeY, $eye, (int) ($eye * 1.2), $inkColor);
        imagefilledellipse($img, $cx + $gap, $eyeY, $eye, (int) ($eye * 1.2), $inkColor);

        imagesetthickness($img, (int) ($s * 0.022));
        $smile = (int) ($head * 0.3);
        imagearc($img, $cx, $eyeY + (int) ($head * 0.1), $smile, (int) ($smile * 0.7), 20, 160, $inkColor);
        imagesetthickness($img, 1);

        // Cheek dot for a bit of life.
        imagefilledellipse($img, $cx + $gap + (int) ($head * 0.08), $eyeY + (int) ($head * 0.12), (int) ($s * 0.05), (int) ($s * 0.035), $white);
    }

    private static function color(GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?? [0, 0, 0];
        $clamp = fn (mixed $v): int => max(0, min(255, (int) $v));

        return (int) imagecolorallocate($img, $clamp($r), $clamp($g), $clamp($b));
    }
}
