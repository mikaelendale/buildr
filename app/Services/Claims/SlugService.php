<?php

namespace App\Services\Claims;

use App\Models\Channel;
use App\Models\Creator;
use App\Models\ReservedSlug;
use Illuminate\Support\Str;

/**
 * Profile slugs (plan §12.3): ^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$,
 * not reserved, unique. Creator profiles and channel short links share the
 * /{slug} namespace, so a slug is taken when either uses it.
 */
class SlugService
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/';

    /**
     * @return string|null an error message, or null when the slug is free
     */
    public function problem(string $slug, ?Creator $ignore = null, ?Channel $ignoreChannel = null): ?string
    {
        if (preg_match(self::PATTERN, $slug) !== 1) {
            return 'Use 3–30 lowercase letters, numbers or dashes (no dash at the start or end).';
        }

        if (ReservedSlug::isReserved($slug)) {
            return 'That name is reserved.';
        }

        $taken = Creator::where('slug', $slug)
            ->when($ignore !== null, fn ($q) => $q->where('id', '!=', $ignore?->id))
            ->exists()
            || Channel::where('slug', $slug)
                ->when($ignoreChannel !== null, fn ($q) => $q->where('id', '!=', $ignoreChannel?->id))
                ->exists();

        return $taken ? 'That name is already taken.' : null;
    }

    /**
     * A free short link for a channel: its username when that fits, else null.
     */
    public function suggestForChannel(Channel $channel): ?string
    {
        $slug = Str::of($channel->username)->lower()->replace('_', '-')->replaceMatches('/-+/', '-')->trim('-')->limit(30, '')->trim('-')->toString();

        return $this->problem($slug, ignoreChannel: $channel) === null ? $slug : null;
    }

    public function suggest(string $from): string
    {
        $base = Str::of(Str::ascii($from))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(26, '')->trim('-')->toString();

        if (strlen($base) < 3) {
            $base = 'builder-'.$base;
            $base = trim($base, '-');
        }

        $slug = $base;
        $i = 2;

        while ($this->problem($slug) !== null) {
            $slug = Str::limit($base, 26, '').'-'.$i++;

            if ($i > 500) {
                $slug = 'builder-'.Str::lower(Str::random(6));
            }
        }

        return $slug;
    }
}
