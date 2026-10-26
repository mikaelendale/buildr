<?php

namespace App\Services\Ai;

use App\Models\Channel;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Content filters before AI (plan §7.7): drop service messages, posts with no
 * real text (stickers/emoji only), deleted posts, and exact duplicates.
 * Forwards and promos are kept but tagged.
 */
class ContentSelector
{
    /**
     * @return Builder<Post>
     */
    public function query(Channel $channel): Builder
    {
        return Post::query()
            ->where('channel_id', $channel->id)
            ->contentful();
    }

    /**
     * @return Collection<int, Post>
     */
    public function eligible(Channel $channel): Collection
    {
        $seen = [];

        return $this->query($channel)
            ->orderBy('msg_id')
            ->get()
            ->filter(function (Post $post) use (&$seen) {
                if (! $this->hasRealText((string) $post->text)) {
                    return false;
                }

                $key = $post->content_hash ?? md5((string) $post->text);

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values();
    }

    public function textPostCount(Channel $channel): int
    {
        return $this->eligible($channel)->count();
    }

    /**
     * At least a few letters or digits; emoji-only and sticker captions don't count.
     */
    public function hasRealText(string $text): bool
    {
        return preg_match_all('/[\p{L}\p{N}]/u', $text) >= 3;
    }
}
