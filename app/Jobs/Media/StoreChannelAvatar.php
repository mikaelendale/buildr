<?php

namespace App\Jobs\Media;

use App\Models\Channel;
use App\Services\Media\AvatarStorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StoreChannelAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $channelId, public string $url)
    {
        $this->onQueue('media');
    }

    public function handle(AvatarStorer $storer): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel === null) {
            return;
        }

        $path = $storer->storeFromUrl($this->url, 'avatars/channels/'.$channel->id);

        if ($path !== null && $path !== $channel->avatar_path) {
            $channel->forceFill(['avatar_path' => $path])->save();
        }
    }
}
