<?php

namespace App\Jobs\Media;

use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Media\AvatarStorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copy the Telegram profile photo into our storage (Telegram URLs change).
 */
class StoreUserAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $userId, public string $url)
    {
        $this->onQueue('media');
    }

    public function handle(AvatarStorer $storer, DirectoryIndexer $indexer): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $path = $storer->storeFromUrl($this->url, 'avatars/users/'.$user->id);

        if ($path !== null) {
            $user->forceFill(['avatar_path' => $path])->save();

            $creator = $user->creator;

            if ($creator !== null && $creator->avatar_path === null) {
                $creator->forceFill(['avatar_path' => $path])->save();

                // Their channels without a photo of their own are listed with this one.
                $indexer->refreshCreator($creator);
            }
        }
    }
}
