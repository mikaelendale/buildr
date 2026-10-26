<?php

use App\Ai\Agents\MonthDigester;
use App\Ai\Agents\PostExtractor;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Post;
use App\Models\Summary;
use App\Services\Ai\AiRunner;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\PostExtractionService;
use App\Services\Ai\Prompts;
use App\Services\Ai\SummaryGenerator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\RateLimitedException;

function groqRateLimit(string $message): RateLimitedException
{
    $response = new Response(new Psr7Response(429, [], json_encode(['error' => ['message' => $message]])));

    return RateLimitedException::forProvider('groq', 429, new RequestException($response));
}

beforeEach(function () {
    config([
        'buildr.ai.budget_profile' => 'compact',
        'buildr.ai.budgets.compact.tokens_per_minute' => 6000,
    ]);
});

it('trims voice examples to the profile budget', function () {
    $examples = app(Prompts::class)->examples(1);

    expect($examples)->toContain('### Example 1')
        ->not->toContain('### Example 2');
});

it('paces calls that would overflow the per-minute token window', function () {
    config(['buildr.ai.budgets.compact.tokens_per_minute' => 4000]);
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
    PostExtractor::fake(fn () => ['posts' => []]);

    $runner = app(AiRunner::class);
    $runner->structured(new PostExtractor('openai/gpt-oss-20b'), 'first', 'extract_posts', 'openai/gpt-oss-20b');
    Sleep::assertNeverSlept();

    $runner->structured(new PostExtractor('openai/gpt-oss-20b'), 'second', 'extract_posts', 'openai/gpt-oss-20b');
    Sleep::assertSleptTimes(1);
});

it('waits out a short provider rate limit and retries', function () {
    config(['buildr.ai.budgets.compact.tokens_per_minute' => 0]);
    Sleep::fake();
    $calls = 0;

    PostExtractor::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw groqRateLimit('Rate limit reached on tokens per minute (TPM). Please try again in 2.5s.');
        }

        return ['posts' => []];
    });

    app(AiRunner::class)->structured(new PostExtractor('openai/gpt-oss-20b'), 'x', 'extract_posts', 'openai/gpt-oss-20b');

    expect($calls)->toBe(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 4.0, 1);
});

it('switches to the fallback model on a daily cap, then reports when quota returns', function () {
    Sleep::fake();
    config(['buildr.ai.fallback_models' => ['openai/gpt-oss-20b' => 'openai/gpt-oss-120b']]);
    PostExtractor::fake(fn () => throw groqRateLimit('Rate limit reached on tokens per day (TPD). Please try again in 1h7m12s.'));

    try {
        app(AiRunner::class)->structured(new PostExtractor('openai/gpt-oss-20b'), 'x', 'extract_posts', 'openai/gpt-oss-20b');
        $this->fail('Expected the quota to be reported as exhausted.');
    } catch (AiQuotaExhaustedException $e) {
        expect($e->retryAfterSeconds)->toBe(4032)
            ->and($e->getMessage())->toContain('tokens per day');
    }

    // Both the model and its fallback were tried and logged; nothing slept.
    expect(AiRun::pluck('model')->all())->toBe(['openai/gpt-oss-20b', 'openai/gpt-oss-120b']);
    Sleep::assertNeverSlept();
});

it('uses the fallback model when it still has quota', function () {
    config(['buildr.ai.fallback_models' => ['openai/gpt-oss-120b' => 'openai/gpt-oss-20b']]);
    $calls = 0;

    PostExtractor::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw groqRateLimit('Rate limit reached on tokens per day (TPD). Please try again in 13m13.584s.');
        }

        return ['posts' => []];
    });

    app(AiRunner::class)->structured(new PostExtractor('openai/gpt-oss-120b'), 'x', 'extract_posts', 'openai/gpt-oss-120b');

    expect(AiRun::where('status', 'ok')->sole()->model)->toBe('openai/gpt-oss-20b');
});

it('samples a representative set of posts when extraction is capped', function () {
    config(['buildr.ai.budgets.compact.extraction_max_posts' => 10]);
    $channel = Channel::factory()->create();

    foreach (range(1, 50) as $i) {
        Post::factory()->for($channel)->create([
            'msg_id' => $i,
            'views' => $i === 7 ? 99_999 : $i,
            'text' => "Post number {$i} about shipping a small tool for builders",
        ]);
    }

    $pending = app(PostExtractionService::class)->pending($channel);
    $ids = $pending->pluck('msg_id');

    expect($pending)->toHaveCount(10)
        ->and($ids)->toContain(7)            // most viewed always kept
        ->and($ids->min())->toBeLessThan(10) // early history covered
        ->and($ids->all())->toBe($ids->sort()->values()->all());
});

it('digests long histories per quarter on small budgets', function () {
    config(['buildr.ai.budgets.compact.tokens_per_minute' => 0]);
    MonthDigester::fake(fn () => ['summary' => 'A busy stretch.', 'cites' => []]);
    $channel = Channel::factory()->create(['timezone' => 'UTC']);

    foreach (range(0, 17) as $i) {
        Post::factory()->for($channel)->create([
            'msg_id' => $i + 1,
            'posted_at' => now()->startOfMonth()->subMonths(17 - $i)->addDays(3),
            'text' => "Month {$i}: shipped another small improvement to the bot today",
        ]);
    }

    $summary = Summary::create([
        'channel_id' => $channel->id,
        'version' => 1,
        'mode' => SummaryMode::Unclaimed,
        'status' => SummaryStatus::Generating,
        'model' => 'test',
        'prompt_version' => 1,
        'triggered_by' => TriggeredBy::System,
    ]);

    $generator = app(SummaryGenerator::class);
    while ($generator->mapMonths($summary->refresh())) {
    }

    $months = collect($summary->refresh()->month_digests)->pluck('month');

    expect($months->count())->toBeLessThanOrEqual(7)
        ->and($months->every(fn ($m) => preg_match('/^\d{4}-Q[1-4]$/', $m) === 1))->toBeTrue();
});

it('skips a model whose daily quota is known to be spent', function () {
    config(['buildr.ai.fallback_models' => ['openai/gpt-oss-120b' => 'openai/gpt-oss-20b']]);
    Cache::put(AiRunner::exhaustedKey('openai/gpt-oss-120b'), true, 600);
    PostExtractor::fake(fn () => ['posts' => []]);

    app(AiRunner::class)->structured(new PostExtractor('openai/gpt-oss-120b'), 'x', 'extract_posts', 'openai/gpt-oss-120b');

    // No doomed request to the spent model: one successful call on the fallback.
    expect(AiRun::pluck('model')->all())->toBe(['openai/gpt-oss-20b']);
});

it('retries a schema-validation failure from the provider', function () {
    $calls = 0;

    PostExtractor::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RequestException(new Response(new Psr7Response(400, [], json_encode(['error' => ['message' => 'Failed to validate JSON. Please adjust your prompt.', 'code' => 'json_validate_failed']]))));
        }

        return ['posts' => []];
    });

    app(AiRunner::class)->structured(new PostExtractor('openai/gpt-oss-20b'), 'x', 'extract_posts', 'openai/gpt-oss-20b');

    expect($calls)->toBe(2)
        ->and(AiRun::pluck('status')->all())->toBe(['error', 'ok']);
});
