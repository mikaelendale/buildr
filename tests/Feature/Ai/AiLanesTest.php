<?php

use App\Ai\Agents\PostExtractor;
use App\Ai\Agents\ProfileWriter;
use App\Ai\Agents\ProjectResolver;
use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use App\Enums\SummaryMode;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Services\Ai\AiLane;
use App\Services\Ai\AiLanes;
use App\Services\Ai\AiRunner;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\ProjectBuilder;
use App\Services\Ai\Prompts;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

/**
 * A small pool: one strong and one solid route, two light ones.
 */
beforeEach(function () {
    config([
        'buildr.ai.lanes' => ['gemini', 'groq'],
        'ai.providers.gemini.key' => 'test',
        'ai.providers.groq.key' => 'test',
        'buildr.ai.routes' => [
            ['provider' => 'gemini', 'model' => 'flash', 'tier' => 1, 'budget' => 'roomy'],
            ['provider' => 'groq', 'model' => 'big-oss', 'tier' => 2, 'budget' => 'compact'],
            ['provider' => 'gemini', 'model' => 'flash-lite', 'tier' => 3, 'budget' => 'roomy'],
            ['provider' => 'groq', 'model' => 'small-oss', 'tier' => 3, 'budget' => 'compact', 'rpd' => 2],
        ],
    ]);
});

function runStep(string $role = 'extraction'): array
{
    return app(AiRunner::class)->run($role, 'step', fn (AiLane $lane, string $model) => [
        $role === 'writer' ? new ProfileWriter($model, SummaryMode::Unclaimed) : new PostExtractor($model),
        'input',
    ], null);
}

it('spreads light work across equally good routes and keeps strong models for the writer', function () {
    PostExtractor::fake(fn () => ['posts' => []]);
    ProfileWriter::fake(fn () => ['hook' => 'x']);

    runStep();
    runStep();
    runStep('writer');

    expect(AiRun::orderBy('id')->pluck('model')->all())->toBe(['flash-lite', 'small-oss', 'flash']);
});

it('moves to the next best route when one is overloaded, and remembers it', function () {
    $calls = 0;

    ProfileWriter::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw ProviderOverloadedException::forProvider('gemini');
        }

        return ['hook' => 'x'];
    });

    runStep('writer');

    $lanes = app(AiLanes::class);

    expect(AiRun::orderBy('id')->get(['model', 'status'])->toArray())->toBe([
        ['model' => 'flash', 'status' => 'error'],
        ['model' => 'big-oss', 'status' => 'ok'],
    ])->and($lanes->blockedFor('gemini', 'flash'))->toBeGreaterThan(0)
        ->and($lanes->health('gemini', 'flash'))->toBeGreaterThan(0.0)
        ->and($lanes->pick('writer', advance: false)?->model('synthesis'))->toBe('big-oss');
});

it('never hands quality steps to light models, and pauses instead', function () {
    $lanes = app(AiLanes::class);
    $lanes->block('gemini', 'flash', 600, true);
    $lanes->block('groq', 'big-oss', 600, true);

    expect($lanes->pick('writer'))->toBeNull();

    ProfileWriter::fake(fn () => ['hook' => 'x']);

    expect(fn () => runStep('writer'))->toThrow(AiQuotaExhaustedException::class);
    expect(AiRun::count())->toBe(0);
});

it('stops using a route at its daily request cap', function () {
    PostExtractor::fake(fn () => ['posts' => []]);
    $lanes = app(AiLanes::class);
    $lanes->block('gemini', 'flash-lite', 600, true);

    runStep();
    runStep();
    runStep();

    // small-oss allows 2 a day; the third light call moves up to the solid route.
    expect(AiRun::orderBy('id')->pluck('model')->all())->toBe(['small-oss', 'small-oss', 'big-oss'])
        ->and($lanes->usedToday('groq', 'small-oss'))->toBe(2);
});

it('loads the Gemini wording of a prompt on Gemini routes only', function () {
    $prompts = app(Prompts::class);
    $gemini = collect(app(AiLanes::class)->all())->firstWhere('provider', 'gemini');
    $groq = collect(app(AiLanes::class)->all())->firstWhere('provider', 'groq');

    expect(AiLanes::using($gemini, fn () => $prompts->get('extraction')))->toContain('<fields>')
        ->and(AiLanes::using($groq, fn () => $prompts->get('extraction')))->not->toContain('<fields>')
        ->and(AiLanes::using($gemini, fn () => $prompts->message('{}', 'posts', 'Task first.', 'Task last.')))->toBe("<posts>\n{}\n</posts>\n\nTask last.")
        ->and(AiLanes::using($groq, fn () => $prompts->message('{}', 'posts', 'Task first.', 'Task last.')))->toBe("Task first.\n\n{}");
});

it('never turns forwards, shared links or AI models into the author\'s projects', function () {
    config(['buildr.ai.lanes' => []]);
    $channel = Channel::factory()->create();
    $label = function (int $id, string $text, string $type, string $name, bool $forward = false) use ($channel) {
        $post = Post::factory()->for($channel)->create(['msg_id' => $id, 'text' => $text, 'is_forward' => $forward]);
        PostExtraction::create(['post_id' => $post->id, 'type' => $type, 'projects' => [['name' => $name, 'role' => 'own']], 'milestone' => 'launched', 'model' => 'test', 'prompt_version' => 1]);
    };

    $label(1, 'Launched Docklet today!', 'project_launch', 'Docklet', forward: true);
    $label(2, 'Tried Lumen Notes, it is great', 'resource_share', 'Lumen Notes');
    $label(3, 'opus 4.8 is out', 'project_launch', 'opus 4.8');
    $stale = Project::create(['channel_id' => $channel->id, 'name' => 'opus', 'slug' => 'opus', 'normalized_name' => 'opus', 'status' => ProjectStatus::Launched, 'source' => ContentSource::Ai]);

    ProjectResolver::fake(fn () => throw new RuntimeException('The resolver should not be called.'));

    app(ProjectBuilder::class)->build($channel);

    expect(Project::find($stale->id))->toBeNull()
        ->and(Project::where('channel_id', $channel->id)->count())->toBe(0);
});
