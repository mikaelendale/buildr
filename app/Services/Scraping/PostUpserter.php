<?php

namespace App\Services\Scraping;

use App\Models\Channel;
use App\Models\Post;
use App\Services\Telegram\Data\ParsedPost;
use App\Support\AdHeuristics;
use App\Support\LanguageDetector;
use App\Support\LinkClassifier;
use Illuminate\Support\Facades\DB;

/**
 * Writes parsed posts for a channel: insert new ones, refresh changed ones,
 * keep msg_id bounds and last_post_at on the channel in sync.
 */
class PostUpserter
{
    /**
     * @param  list<ParsedPost>  $posts
     * @return int number of posts that were new
     */
    public function upsert(Channel $channel, array $posts): int
    {
        if ($posts === []) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($channel, $posts, &$created) {
            $existing = Post::where('channel_id', $channel->id)
                ->whereIn('msg_id', array_map(fn (ParsedPost $p) => $p->msgId, $posts))
                ->get()
                ->keyBy('msg_id');

            foreach ($posts as $parsed) {
                /** @var Post|null $post */
                $post = $existing->get($parsed->msgId);
                $hash = $parsed->contentHash();

                $attributes = [
                    'posted_at' => $parsed->postedAt,
                    'edited' => $parsed->edited,
                    'views' => $parsed->views,
                    'reactions' => $parsed->reactions === [] ? null : $parsed->reactions,
                    'is_deleted' => false,
                    'scraped_at' => now(),
                    'views_updated_at' => now(),
                ];

                if ($post === null || $post->content_hash !== $hash) {
                    $attributes += [
                        'text' => $parsed->text,
                        'text_html' => $parsed->textHtml,
                        'lang' => LanguageDetector::detect($parsed->text),
                        'has_media' => $parsed->hasMedia(),
                        'media_types' => $parsed->mediaTypes === [] ? null : $parsed->mediaTypes,
                        'is_forward' => $parsed->isForward,
                        'forwarded_from' => $parsed->forwardedFrom,
                        'reply_to_msg_id' => $parsed->replyToMsgId,
                        'hashtags' => $parsed->hashtags === [] ? null : $parsed->hashtags,
                        'is_service' => $parsed->isService,
                        'is_ad' => AdHeuristics::looksLikeAd($parsed->text, count($parsed->links)),
                        'content_hash' => $hash,
                    ];
                }

                if ($post === null) {
                    $post = Post::create(['channel_id' => $channel->id, 'msg_id' => $parsed->msgId] + $attributes);
                    $created++;
                    $this->syncLinks($post, $parsed->links);
                } else {
                    $contentChanged = array_key_exists('content_hash', $attributes);
                    $post->update($attributes);

                    if ($contentChanged) {
                        $this->syncLinks($post, $parsed->links);
                        // Edited text means the old extraction no longer describes the post.
                        $post->extraction()->delete();
                    }
                }
            }

            $this->refreshBounds($channel);
        });

        return $created;
    }

    /**
     * Mark posts as deleted when they fall inside a fully fetched msg_id range
     * but no longer appear on Telegram.
     *
     * @param  list<int>  $seenMsgIds
     */
    public function markMissingAsDeleted(Channel $channel, int $fromMsgId, int $toMsgId, array $seenMsgIds): int
    {
        return Post::where('channel_id', $channel->id)
            ->whereBetween('msg_id', [$fromMsgId, $toMsgId])
            ->whereNotIn('msg_id', $seenMsgIds)
            ->where('is_deleted', false)
            ->update(['is_deleted' => true]);
    }

    /**
     * @param  list<string>  $links
     */
    private function syncLinks(Post $post, array $links): void
    {
        $post->links()->delete();

        foreach (array_slice($links, 0, 25) as $url) {
            $domain = LinkClassifier::domain($url);

            if ($domain === null) {
                continue;
            }

            $post->links()->create([
                'url' => mb_substr($url, 0, 1000),
                'domain' => $domain,
                'kind' => LinkClassifier::classify($url),
            ]);
        }
    }

    private function refreshBounds(Channel $channel): void
    {
        $bounds = Post::where('channel_id', $channel->id)
            ->selectRaw('min(msg_id) as min_id, max(msg_id) as max_id, max(posted_at) as last_post')
            ->first();

        $channel->forceFill([
            'oldest_msg_id' => $bounds?->getAttribute('min_id'),
            'newest_msg_id' => $bounds?->getAttribute('max_id'),
            'last_post_at' => $bounds?->getAttribute('last_post'),
        ])->save();
    }
}
