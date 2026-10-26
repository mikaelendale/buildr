<?php

use App\Ai\Agents\PostExtractor;
use App\Ai\Agents\ProjectResolver;
use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Services\Ai\AiLane;
use App\Services\Ai\AiLanes;
use App\Services\Ai\AiRunner;
use App\Services\Ai\ProjectBuilder;
use App\Services\Ai\Prompts;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

beforeEach(function () {
    config([
        'buildr.ai.lanes' => ['gemini', 'groq'],
        'buildr.ai.lane_options.gemini.tokens_per_minute' => 0,
        'buildr.ai.lane_options.gemini.requests_per_minute' => 0,
        'buildr.ai.lane_options.groq.tokens_per_minute' => 0,
        'buildr.ai.lane_options.groq.requests_per_minute' => 0,
    ]);
});

function runExtraction(): array
{
    return app(AiRunner::class)->run('extraction', 'extract_posts', fn (AiLane $lane, string $model) => [new PostExtractor($model), 'posts'], null);
}

it('takes turns between lanes for high-volume steps', function () {
    PostExtractor::fake(fn () => ['posts' => []]);

    runExtraction();
    runExtraction();
    runExtraction();

    expect(AiRun::orderBy('id')->pluck('model')->all())
        ->toBe(['gemini-3.5-flash-lite', 'openai/gpt-oss-20b', 'gemini-3.5-flash-lite']);
});

it('hands a step to the next lane when a provider is overloaded', function () {
    config(['buildr.ai.roles.extraction' => 'prefer', 'buildr.ai.fallback_models' => []]);
    $calls = 0;

    PostExtractor::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw ProviderOverloadedException::forProvider('gemini');
        }

        return ['posts' => []];
    });

    runExtraction();

    expect(AiRun::orderBy('id')->get(['model', 'status'])->toArray())->toBe([
        ['model' => 'gemini-3.5-flash-lite', 'status' => 'error'],
        ['model' => 'openai/gpt-oss-20b', 'status' => 'ok'],
    ])->and(app(AiLanes::class)->blockedFor('gemini', 'gemini-3.5-flash-lite'))->toBeGreaterThan(0);

    // While Gemini cools down, the next step goes straight to Groq.
    runExtraction();
    expect(AiRun::latest('id')->value('model'))->toBe('openai/gpt-oss-20b');
});

it('loads the Gemini wording of a prompt on the Gemini lane only', function () {
    $prompts = app(Prompts::class);
    $lanes = app(AiLanes::class)->all();

    $gemini = AiLanes::using($lanes[0], fn () => $prompts->get('extraction'));
    $groq = AiLanes::using($lanes[1], fn () => $prompts->get('extraction'));

    expect($gemini)->toContain('<fields>')
        ->and($groq)->not->toContain('<fields>')
        ->and(AiLanes::using($lanes[0], fn () => $prompts->message('{}', 'posts', 'Task first.', 'Task last.')))->toBe("<posts>\n{}\n</posts>\n\nTask last.")
        ->and(AiLanes::using($lanes[1], fn () => $prompts->message('{}', 'posts', 'Task first.', 'Task last.')))->toBe("Task first.\n\n{}");
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
