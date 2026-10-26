<?php

use App\Support\GeneratedAvatar;

it('draws deterministic png avatars in both styles', function (string $style) {
    $a = GeneratedAvatar::png('shipfast_mobile', $style, 'Ship Fast');
    $b = GeneratedAvatar::png('shipfast_mobile', $style, 'Ship Fast');
    $size = getimagesizefromstring($a);

    expect($a)->toBe($b)
        ->and($size[0] ?? null)->toBe(256)
        ->and($size['mime'] ?? null)->toBe('image/png')
        ->and(GeneratedAvatar::png('another_channel', $style))->not->toBe($a);
})->with(['app', 'face']);
