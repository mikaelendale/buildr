<?php

namespace Database\Seeders;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ContentSource;
use App\Enums\CreatorStatus;
use App\Enums\PostLang;
use App\Enums\ProjectStatus;
use App\Enums\SocialPlatform;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Models\Channel;
use App\Models\ChannelSnapshot;
use App\Models\Creator;
use App\Models\FeaturedItem;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Models\Summary;
use App\Models\Tag;
use App\Models\User;
use App\Services\Ai\StatsCalculator;
use App\Services\Directory\DirectoryIndexer;
use App\Support\GeneratedAvatar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local-only demo content so the public site has something to show. Every
 * name here is fictional.
 */
class DemoSeeder extends Seeder
{
    private const PEOPLE = [
        [
            'name' => 'Selam Tesfaye', 'slug' => 'selam', 'am' => 'ሰላም ተስፋዬ', 'channel' => 'selambuilds', 'title' => 'Selam Builds',
            'project' => 'Birr Invoice', 'topic' => 'Laravel', 'tags' => ['web-dev', 'startups'], 'lang' => PostLang::Mixed,
            'hook' => 'Ships on Fridays, fixes on Saturdays, and has not skipped a week since February.',
            'vibes' => ['Builder', 'Shipper', 'Open Book'],
        ],
        [
            'name' => 'Dawit Bekele', 'slug' => 'dawit', 'am' => 'ዳዊት በቀለ', 'channel' => 'dawit_ai', 'title' => 'Dawit on AI',
            'project' => 'Amharic OCR Kit', 'topic' => 'AI', 'tags' => ['ai', 'open-source'], 'lang' => PostLang::En,
            'hook' => 'Breaks a new AI tool before breakfast, then writes the guide so you do not have to.',
            'vibes' => ['Teacher', 'Tinkerer', 'AI'],
        ],
        [
            'name' => 'Hanna Girma', 'slug' => 'hanna', 'am' => null, 'channel' => 'hanna_designs', 'title' => 'Hanna Designs',
            'project' => 'Gebeya UI Kit', 'topic' => 'Design', 'tags' => ['design'], 'lang' => PostLang::En,
            'hook' => 'Turns messy app screens into calm ones, one teardown at a time.',
            'vibes' => ['Designer', 'Curator'],
        ],
    ];

    private const CHANNELS = [
        ['username' => 'addis_devs_daily', 'title' => 'Addis Devs Daily', 'project' => 'Job Board Bot', 'topic' => 'Web Dev', 'tags' => ['web-dev', 'students'],
            'hook' => 'A daily drip of dev tips, job posts and tiny tools for students.', 'vibes' => ['Curator', 'Daily']],
        ['username' => 'cryptonest_et', 'title' => 'CryptoNest ET', 'project' => 'Wallet Watch', 'topic' => 'Crypto', 'tags' => ['crypto'],
            'hook' => 'Explains wallets and chains in plain language, with a weekly market recap.', 'vibes' => ['Explainer', 'Weekly']],
        ['username' => 'shipfast_mobile', 'title' => 'Ship Fast Mobile', 'project' => 'Taxi Fare Calc', 'topic' => 'Flutter', 'tags' => ['mobile'],
            'hook' => 'Flutter builds, store releases, and honest postmortems.', 'vibes' => ['Builder', 'Mobile']],
    ];

    public function run(): void
    {
        $stats = app(StatsCalculator::class);
        $indexer = app(DirectoryIndexer::class);
        $sort = 0;

        foreach (self::PEOPLE as $i => $person) {
            $user = User::factory()->create(['name' => $person['name'], 'telegram_username' => $person['channel']]);

            $creator = Creator::create([
                'user_id' => $user->id,
                'slug' => $person['slug'],
                'display_name' => Str::before($person['name'], ' '),
                'real_name' => $person['name'],
                'real_name_am' => $person['am'],
                'bio' => "Building {$person['project']} in public. Writing about {$person['topic']} and the small decisions behind shipping.",
                'location' => 'Addis Ababa',
                'status' => CreatorStatus::Published,
                'onboarding_step' => 6,
                'onboarding_completed_at' => now(),
                'published_at' => now()->subDays(10 - $i),
                'timezone' => 'Africa/Addis_Ababa',
            ]);

            $creator->socials()->createMany([
                ['platform' => SocialPlatform::X, 'url' => "https://x.com/{$person['slug']}", 'handle' => $person['slug'], 'sort' => 0],
                ['platform' => SocialPlatform::Github, 'url' => "https://github.com/{$person['slug']}", 'handle' => $person['slug'], 'sort' => 1],
            ]);

            $channel = $this->channel($person['channel'], $person['title'], ChannelClaimStatus::Claimed, $creator->id, $person['lang']);
            $avatar = $this->avatar($person['channel'], 'face');
            $creator->update(['primary_channel_id' => $channel->id, 'avatar_path' => $avatar]);
            $channel->update(['avatar_path' => $avatar]);
            $this->content($channel, $person['project'], $person['topic'], $person['hook'], $person['vibes'], SummaryMode::Claimed, $creator->id);
            $this->tag($creator, $person['tags']);
            $stats->compute($channel);
            $indexer->refreshCreator($creator);

            FeaturedItem::create(['featurable_type' => 'creator', 'featurable_id' => $creator->id, 'sort' => $sort++]);
        }

        foreach (self::CHANNELS as $data) {
            $channel = $this->channel($data['username'], $data['title'], ChannelClaimStatus::Unclaimed, null, PostLang::En);
            $channel->update([
                'visibility' => ChannelVisibility::Published,
                'published_at' => now()->subDays(3),
                'avatar_path' => $this->avatar($data['username'], 'app', $data['title']),
            ]);
            $this->content($channel, $data['project'], $data['topic'], $data['hook'], $data['vibes'], SummaryMode::Unclaimed, null);
            $this->tag($channel, $data['tags']);
            $stats->compute($channel);
            $indexer->refreshChannel($channel);

            FeaturedItem::create(['featurable_type' => 'channel', 'featurable_id' => $channel->id, 'sort' => $sort++]);
        }
    }

    /**
     * Designed placeholder avatar (no stock photos): faces for people, app icons for channels.
     */
    private function avatar(string $seed, string $style, ?string $label = null): string
    {
        $path = "avatars/demo/{$seed}.png";
        Storage::disk('public')->put($path, GeneratedAvatar::png($seed, $style, $label));

        return $path;
    }

    private function channel(string $username, string $title, ChannelClaimStatus $claim, ?int $creatorId, PostLang $lang): Channel
    {
        $subscribers = random_int(800, 60_000);

        $channel = Channel::create([
            'username' => $username,
            'title' => $title,
            'description' => "{$title}: notes from building in public.",
            'subscribers' => $subscribers,
            'creator_id' => $creatorId,
            'claim_status' => $claim,
            'visibility' => ChannelVisibility::Hidden,
            'added_by' => $creatorId ? ChannelAddedBy::Self : ChannelAddedBy::Admin,
            'backfill_complete' => true,
            'timezone' => 'Africa/Addis_Ababa',
            'last_scraped_at' => now(),
            'last_processed_at' => now(),
        ]);

        ChannelSnapshot::create(['channel_id' => $channel->id, 'subscribers' => (int) ($subscribers * 0.9), 'captured_at' => now()->subDays(31)]);
        ChannelSnapshot::create(['channel_id' => $channel->id, 'subscribers' => $subscribers, 'captured_at' => now()]);

        foreach (range(1, 40) as $n) {
            $am = $lang === PostLang::Mixed && $n % 3 === 0;

            Post::create([
                'channel_id' => $channel->id,
                'msg_id' => $n,
                'posted_at' => now()->subWeeks(40 - $n)->setTime(random_int(6, 23), random_int(0, 59)),
                'text' => $am
                    ? "ዛሬ አዲስ ባህሪ ጨምረናል። Week {$n} update."
                    : "Week {$n}: shipped another improvement and wrote down what I learned along the way.",
                'lang' => $am ? PostLang::Mixed : PostLang::En,
                'views' => random_int(200, 9000),
                'content_hash' => md5("{$username}-{$n}"),
                'scraped_at' => now(),
            ]);
        }

        $channel->update(['newest_msg_id' => 40, 'oldest_msg_id' => 1, 'last_post_at' => now()]);

        return $channel;
    }

    /**
     * @param  list<string>  $vibes
     */
    private function content(Channel $channel, string $projectName, string $topic, string $hook, array $vibes, SummaryMode $mode, ?int $creatorId): void
    {
        $posts = Post::where('channel_id', $channel->id)->orderBy('msg_id')->get();

        foreach ($posts as $post) {
            PostExtraction::create([
                'post_id' => $post->id,
                'type' => $post->msg_id % 10 === 0 ? 'project_launch' : 'progress_update',
                'topics' => [$topic],
                'projects' => [['name' => $projectName, 'role' => 'own']],
                'tools' => [$topic, 'Telegram Bot API'],
                'milestone' => $post->msg_id === 30 ? 'launched v1' : null,
                'tone' => 'casual',
                'is_personal_sensitive' => false,
                'model' => 'demo',
                'prompt_version' => 1,
            ]);
        }

        Project::create([
            'channel_id' => $channel->id,
            'creator_id' => $creatorId,
            'name' => $projectName,
            'slug' => Str::slug($projectName),
            'normalized_name' => Str::lower($projectName),
            'url' => 'https://example.com/'.Str::slug($projectName),
            'description' => "{$projectName} helps people in Ethiopia get more done with less hassle.",
            'status' => ProjectStatus::Launched,
            'first_seen_at' => $posts->first()?->posted_at,
            'last_seen_at' => $posts->last()?->posted_at,
            'mention_count' => 24,
            'source' => ContentSource::Ai,
        ]);

        $slug = Str::slug($projectName);

        Summary::create([
            'channel_id' => $channel->id,
            'creator_id' => $creatorId,
            'version' => 1,
            'mode' => $mode,
            'status' => SummaryStatus::Verified,
            'model' => 'demo',
            'prompt_version' => 1,
            'triggered_by' => TriggeredBy::System,
            'citations' => ['hook' => [30], 'intro' => [10, 20]],
            'content' => [
                'hook' => $hook,
                'vibe_tags' => $vibes,
                'intro' => $mode === SummaryMode::Claimed
                    ? "{$channel->title} documents building {$projectName} week by week, with honest notes on what worked and what broke."
                    : "This channel documents building {$projectName} week by week, with honest notes on what worked and what broke.",
                'building_now' => [['project_slug' => $slug, 'line' => "{$projectName} is live and gaining its first regular users.", 'cites' => [30, 36]]],
                'eras' => [
                    ['name' => 'The First Sketches', 'from' => now()->subWeeks(39)->format('Y-m'), 'to' => now()->subWeeks(20)->format('Y-m'), 'story' => "Early posts explored {$topic} with small experiments and tutorials.", 'cites' => [2, 8]],
                    ['name' => 'Launch Season', 'from' => now()->subWeeks(19)->format('Y-m'), 'to' => now()->format('Y-m'), 'story' => "{$projectName} went from prototype to launch, with weekly progress updates.", 'cites' => [30, 38]],
                ],
                'start_here' => [
                    ['msg_id' => 30, 'why' => 'The launch post with the full story.'],
                    ['msg_id' => 12, 'why' => 'A clear example of the weekly update style.'],
                ],
                'in_their_words' => [['msg_id' => 5, 'quote' => 'shipped another improvement and wrote down what I learned along the way.']],
                'how_they_work' => $mode === SummaryMode::Claimed ? ['text' => "{$topic} for the core, Telegram for distribution, and a strict weekly release habit.", 'cites' => [12]] : null,
                'fun_facts' => [['text' => 'Has posted 40 times so far.', 'source' => 'stats.total_posts']],
            ],
            'verification' => ['passed' => true, 'dropped' => [], 'llm' => []],
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function tag(Creator|Channel $model, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $tag = Tag::where('slug', $slug)->first();

            if ($tag !== null) {
                $model->tags()->syncWithoutDetaching([$tag->id => ['source' => 'admin']]);
            }
        }
    }
}
