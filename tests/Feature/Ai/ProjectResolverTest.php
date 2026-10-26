<?php

use App\Ai\Agents\ProjectResolver;
use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Services\Ai\ProjectBuilder;
use Tests\Support\FakesAi;

uses(FakesAi::class);

/**
 * A post whose extraction says the author builds $names.
 *
 * @param  list<string>  $names
 */
function postBuilding(Channel $channel, int $msgId, string $text, array $names, string $date, ?string $link = null, ?string $milestone = null): Post
{
    $post = Post::factory()->for($channel)->create(['msg_id' => $msgId, 'text' => $text, 'posted_at' => $date]);

    if ($link !== null) {
        $post->links()->create(['url' => $link, 'domain' => (string) parse_url($link, PHP_URL_HOST), 'kind' => 'product']);
    }

    PostExtraction::create([
        'post_id' => $post->id,
        'type' => 'progress_update',
        'projects' => array_map(fn (string $n) => ['name' => $n, 'role' => 'own'], $names),
        'milestone' => $milestone,
        'model' => 'test',
        'prompt_version' => 1,
    ]);

    return $post;
}

it('derives brand tokens from names and domains', function (string $name, string $brand) {
    expect(ProjectBuilder::brand($name))->toBe($brand);
})->with([
    ['RAVN', 'ravn'],
    ['useravn.com', 'ravn'],
    ['getravn.xyz', 'ravn'],
    ['https://www.getravn.xyz/pricing', 'ravn'],
    ['mikaelen.site', 'mikaelen'],
    ['loan.mikaelen.site', 'loan mikaelen'],
    ['Forma', 'forma'],
]);

it('merges domain moves into one project and drops what the author does not build', function () {
    $this->fakeAi();
    $channel = Channel::factory()->create(['username' => 'shipper']);

    postBuilding($channel, 1, 'RAVN beta is out for robot fleets.', ['RAVN'], '2026-05-02', 'https://getravn.xyz', 'launched beta');
    postBuilding($channel, 2, 'New home for RAVN: getravn.xyz is now useravn.com.', ['useravn.com', 'getravn.xyz'], '2026-09-10', 'https://useravn.com');
    postBuilding($channel, 3, 'Played with Opus 4.8 today, wild.', ['Opus 4.8'], '2026-09-12');

    $stale = Project::create(['channel_id' => $channel->id, 'name' => 'opus 4.8', 'slug' => 'opus-4-8', 'normalized_name' => 'opus 4 8', 'status' => ProjectStatus::Building, 'source' => ContentSource::Ai]);
    $hidden = Project::create(['channel_id' => $channel->id, 'name' => 'Old thing', 'slug' => 'old-thing', 'normalized_name' => 'old thing', 'status' => ProjectStatus::Unknown, 'source' => ContentSource::Ai, 'hidden' => true]);
    $mine = Project::create(['channel_id' => $channel->id, 'name' => 'My course', 'slug' => 'my-course', 'normalized_name' => 'my course', 'status' => ProjectStatus::Launched, 'source' => ContentSource::Creator]);

    ProjectResolver::fake(function (string $prompt) {
        $candidates = $this->payload($prompt)['candidates'];
        $ravn = collect($candidates)->first(fn ($c) => collect($c['names'])->contains('RAVN'));
        // Well-known AI models never reach the resolver.
        expect(collect($candidates)->pluck('names')->flatten())->not->toContain('Opus 4.8');

        return [
            'projects' => [[
                'members' => [$ravn['key']],
                'name' => 'RAVN',
                'status' => 'launched',
                'url' => 'https://useravn.com',
                'description' => 'Self-healing infrastructure for robot fleets.',
                'history' => 'Moved from getravn.xyz to useravn.com in Sep 2026.',
            ]],
            'dropped' => [],
        ];
    });

    app(ProjectBuilder::class)->build($channel);

    $projects = Project::where('channel_id', $channel->id)->get()->keyBy('name');
    $ravn = $projects->get('RAVN');

    expect($ravn)->not->toBeNull()
        ->and($ravn->url)->toBe('https://useravn.com')
        ->and($ravn->status)->toBe(ProjectStatus::Launched)
        ->and($ravn->history)->toContain('useravn.com')
        ->and($ravn->aliases)->toContain('useravn.com', 'getravn.xyz')
        ->and($ravn->mention_count)->toBe(2)
        ->and(Project::find($stale->id))->toBeNull()
        ->and(Project::find($hidden->id))->not->toBeNull()
        ->and(Project::find($mine->id))->not->toBeNull()
        ->and($projects->keys()->all())->not->toContain('opus 4.8', 'useravn.com', 'getravn.xyz');

    // The resolver saw one pre-grouped RAVN candidate carrying both domains.
    ProjectResolver::assertPrompted(fn ($p) => str_contains($p->prompt, 'getravn.xyz') && str_contains($p->prompt, 'useravn.com'));
});

it('never lets the resolver invent a url', function () {
    $this->fakeAi();
    $channel = Channel::factory()->create(['username' => 'shipper']);
    postBuilding($channel, 1, 'Shipped Forma today.', ['Forma'], '2026-04-02');

    ProjectResolver::fake(fn (string $prompt) => [
        'projects' => [[
            'members' => [$this->payload($prompt)['candidates'][0]['key']],
            'name' => 'Forma',
            'status' => 'launched',
            'url' => 'https://made-up.example',
            'description' => 'A streaming proxy for LLM output.',
            'history' => null,
        ]],
        'dropped' => [],
    ]);

    app(ProjectBuilder::class)->build($channel);

    expect(Project::where('channel_id', $channel->id)->sole()->url)->toBeNull();
});

it('accepts names instead of keys and keeps projects the reply skipped', function () {
    $this->fakeAi();
    $channel = Channel::factory()->create(['username' => 'shipper']);
    postBuilding($channel, 1, 'Shipped Forma today.', ['Forma'], '2026-04-02');
    postBuilding($channel, 2, 'Waitlyst got a new dashboard.', ['Waitlyst'], '2026-05-02');
    $waitlyst = Project::create(['channel_id' => $channel->id, 'name' => 'Waitlyst', 'slug' => 'waitlyst', 'normalized_name' => 'waitlyst', 'status' => ProjectStatus::Building, 'source' => ContentSource::Ai]);

    ProjectResolver::fake(fn () => [
        'projects' => [[
            'members' => ['Forma'], // a name, not "c1"
            'name' => 'Forma',
            'status' => 'launched',
            'url' => null,
            'description' => 'A streaming proxy for LLM output.',
            'history' => null,
        ]],
        'dropped' => [],
    ]);

    app(ProjectBuilder::class)->build($channel);

    expect(Project::where('channel_id', $channel->id)->pluck('name')->sort()->values()->all())->toBe(['Forma', 'Waitlyst'])
        ->and(Project::find($waitlyst->id))->not->toBeNull();
});

it('leaves projects untouched when the resolver returns nothing usable', function () {
    $this->fakeAi();
    $channel = Channel::factory()->create(['username' => 'shipper']);
    postBuilding($channel, 1, 'RAVN is live.', ['RAVN'], '2026-05-02');
    $ravn = Project::create(['channel_id' => $channel->id, 'name' => 'RAVN', 'slug' => 'ravn', 'normalized_name' => 'ravn', 'status' => ProjectStatus::Launched, 'source' => ContentSource::Ai, 'description' => 'Runtime for robot fleets.']);

    ProjectResolver::fake(fn () => ['projects' => [['members' => ['zz9'], 'name' => 'Ghost', 'status' => 'idea', 'url' => null, 'description' => 'x', 'history' => null]], 'dropped' => []]);

    app(ProjectBuilder::class)->build($channel);

    expect(Project::where('channel_id', $channel->id)->sole()->id)->toBe($ravn->id)
        ->and($ravn->refresh()->description)->toBe('Runtime for robot fleets.');
});
