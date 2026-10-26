<?php

namespace App\Services\Creators;

use App\Enums\ChannelClaimStatus;
use App\Enums\CreatorStatus;
use App\Enums\SocialPlatform;
use App\Models\AuditLog;
use App\Models\Creator;
use App\Models\Tag;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Creator-side edits with the post-publish rules from plan §6.4:
 * bio/socials/tags/location go live immediately (logged for spot checks);
 * display name, real names and slug wait for review once published.
 */
class CreatorProfileService
{
    public const REVIEWED_FIELDS = ['display_name', 'real_name', 'real_name_am', 'slug'];

    public function __construct(
        private readonly DirectoryIndexer $indexer,
        private readonly TelegramNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> fields that now wait for review
     */
    public function updateBasics(Creator $creator, array $data): array
    {
        $published = $creator->isPublished();
        $pending = [];
        $direct = Arr::only($data, ['bio', 'location', 'timezone']);

        foreach (self::REVIEWED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] === '' ? null : $data[$field];

            if ($value === $creator->{$field}) {
                continue;
            }

            if ($published) {
                $pending[$field] = $value;
            } else {
                $direct[$field] = $value;
            }
        }

        if (isset($direct['slug']) && $direct['slug'] !== $creator->slug) {
            $direct['slug_changed_at'] = now();
        }

        $creator->fill($direct);

        if ($pending !== []) {
            $creator->pending_changes = array_merge($creator->pending_changes ?? [], $pending);
        }

        $creator->save();

        if (array_key_exists('socials', $data)) {
            $this->saveSocials($creator, (array) $data['socials']);
        }

        if (array_key_exists('tags', $data)) {
            $this->saveTags($creator, (array) $data['tags']);
        }

        if ($published) {
            AuditLog::record('creator.edited', $creator, ['fields' => array_keys(Arr::except($data, ['socials', 'tags'])), 'pending' => array_keys($pending)]);
            $this->indexer->refreshCreator($creator);

            if ($pending !== []) {
                $this->notifier->toAdmin('Field change waiting for review on <b>'.e($creator->display_name).'</b>: '.implode(', ', array_keys($pending)).' — '.url('/admin/review'));
            }
        }

        return array_keys($pending);
    }

    /**
     * Rows arrive from the request as `{url, platform?}`; anything else is skipped.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public function saveSocials(Creator $creator, array $rows): void
    {
        $creator->socials()->delete();
        $sort = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['url'] ?? null) || trim($row['url']) === '') {
                continue;
            }

            $url = $this->normalizeUrl($row['url']);
            $platform = (is_string($row['platform'] ?? null) ? SocialPlatform::tryFrom($row['platform']) : null)
                ?? self::detectPlatform($url);

            $creator->socials()->create([
                'platform' => $platform,
                'url' => $url,
                'handle' => self::handleFromUrl($url, $platform),
                'sort' => $sort++,
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $names
     */
    public function saveTags(Creator $creator, array $names): void
    {
        $ids = collect(array_filter($names, 'is_string'))
            ->map(fn (string $n) => trim($n))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 30)
            ->unique(fn ($n) => Str::slug($n))
            ->take(5)
            ->mapWithKeys(fn ($n) => [Tag::findOrCreateByName($n)->id => ['source' => 'creator']])
            ->all();

        $creator->tags()->sync($ids);
    }

    /**
     * Submit for review (plan §6.3 step 6). Nothing is published without a verified channel.
     */
    public function submit(Creator $creator): bool
    {
        $hasVerified = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->exists();

        if (! $hasVerified || in_array($creator->status, [CreatorStatus::PendingReview, CreatorStatus::Published], true)) {
            return false;
        }

        $creator->forceFill([
            'status' => CreatorStatus::PendingReview,
            'submitted_at' => now(),
            'onboarding_completed_at' => $creator->onboarding_completed_at ?? now(),
            'onboarding_step' => 6,
            'review_note' => null,
        ])->save();

        AuditLog::record('creator.submitted', $creator);
        $this->notifier->toAdmin('New profile submitted: <b>'.e($creator->display_name).'</b> — '.url('/admin/review/'.$creator->id));

        return true;
    }

    public static function detectPlatform(string $url): SocialPlatform
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;

        return match (true) {
            in_array($host, ['x.com', 'twitter.com'], true) => SocialPlatform::X,
            $host === 'github.com' => SocialPlatform::Github,
            str_ends_with($host, 'linkedin.com') => SocialPlatform::Linkedin,
            $host === 'instagram.com' => SocialPlatform::Instagram,
            $host === 'tiktok.com' => SocialPlatform::Tiktok,
            in_array($host, ['youtube.com', 'youtu.be'], true) => SocialPlatform::Youtube,
            str_ends_with($host, 'substack.com') => SocialPlatform::Substack,
            $host === '' => SocialPlatform::Other,
            default => SocialPlatform::Website,
        };
    }

    public static function handleFromUrl(string $url, SocialPlatform $platform): ?string
    {
        if (in_array($platform, [SocialPlatform::Website, SocialPlatform::Other], true)) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $first = explode('/', $path)[0];

        if ($platform === SocialPlatform::Linkedin) {
            $first = explode('/', $path)[1] ?? $first;
        }

        $handle = ltrim($first, '@');

        return $handle !== '' ? Str::limit($handle, 60, '') : null;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.ltrim($url, '/');
    }
}
