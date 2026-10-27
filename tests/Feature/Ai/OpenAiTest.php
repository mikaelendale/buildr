<?php

use App\Ai\Agents\PostExtractor;
use App\Ai\Agents\ProfileWriter;
use App\Enums\SummaryMode;
use App\Models\AiRun;
use App\Services\Ai\AiRunner;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\RateLimitedException;

function openAiRateLimit(string $message, string $code = 'rate_limit_exceeded'): RateLimitedException
{
    $response = new Response(new Psr7Response(429, [], json_encode(['error' => ['message' => $message, 'type' => $code, 'code' => $code]])));

    return RateLimitedException::forProvider('openai', 429, new RequestException($response));
}

beforeEach(function () {
    config([
        'buildr.ai.provider' => 'openai',
        'buildr.ai.lanes' => [],
        'buildr.ai.budget_profile' => 'standard',
        'buildr.ai.fallback_models' => [],
    ]);
});

it('prices OpenAI calls from the list rates, cached input and reasoning included', function () {
    $runner = app(AiRunner::class);

    // 1M input of which 200K cached, 100K output (reasoning counts as output).
    expect($runner->estimateCost('gpt-5-nano', 1_000_000, 100_000, 200_000))->toBe((800_000 * 0.05 + 200_000 * 0.005 + 100_000 * 0.40) / 1_000_000)
        ->and($runner->estimateCost('gpt-5.6-luna', 10_000, 2_000))->toBe((10_000 * 0.20 + 2_000 * 1.20) / 1_000_000)
        // Dated snapshots cost what their model costs.
        ->and($runner->estimateCost('gpt-5-nano-2025-08-07', 1_000, 1_000))->toBe($runner->estimateCost('gpt-5-nano', 1_000, 1_000))
        // Unknown models are priced high on purpose.
        ->and($runner->estimateCost('gpt-9-mystery', 1_000_000, 0))->toBe(4.0);
});

it('logs each call with its tokens and cost', function () {
    PostExtractor::fake(fn () => ['posts' => []]);

    app(AiRunner::class)->structured(new PostExtractor('gpt-5-nano'), 'x', 'extract_posts', 'gpt-5-nano');

    $run = AiRun::sole();
    expect($run->model)->toBe('gpt-5-nano')
        ->and($run->status)->toBe('ok')
        ->and((float) $run->cost_usd_est)->toBe(app(AiRunner::class)->estimateCost('gpt-5-nano', $run->input_tokens, $run->output_tokens));
});

it('sets reasoning per step: none for labelling, a little for writing and checking', function () {
    $extractor = (new PostExtractor('gpt-5-nano'))->providerOptions('openai');
    $writer = (new ProfileWriter('gpt-5.6-luna', SummaryMode::Claimed))->providerOptions('openai');

    expect($extractor['reasoning'])->toBe(['effort' => 'minimal'])
        ->and($extractor['max_output_tokens'])->toBeGreaterThan(0)
        ->and($writer['reasoning'])->toBe(['effort' => 'low'])
        ->and((new PostExtractor('gpt-5.6-luna'))->providerOptions('openai')['reasoning'])->toBe(['effort' => 'none'])
        // Models that don't reason take no setting at all.
        ->and((new PostExtractor('gpt-4.1-mini'))->providerOptions('openai'))->not->toHaveKey('reasoning');
});

it('waits out an OpenAI rate limit given in milliseconds', function () {
    Sleep::fake();
    $calls = 0;

    PostExtractor::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw openAiRateLimit('Rate limit reached for gpt-5-nano on tokens per min (TPM): Limit 200000, Used 199800, Requested 900. Please try again in 120ms.');
        }

        return ['posts' => []];
    });

    app(AiRunner::class)->structured(new PostExtractor('gpt-5-nano'), 'x', 'extract_posts', 'gpt-5-nano');

    expect($calls)->toBe(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 2.0, 1);
});

it('holds off for an hour when the OpenAI account is out of credit', function () {
    Sleep::fake();
    PostExtractor::fake(fn () => throw openAiRateLimit('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota'));

    try {
        app(AiRunner::class)->structured(new PostExtractor('gpt-5-nano'), 'x', 'extract_posts', 'gpt-5-nano');
        $this->fail('Expected the quota to be reported as exhausted.');
    } catch (AiQuotaExhaustedException $e) {
        expect($e->retryAfterSeconds)->toBe(3600);
    }

    Sleep::assertNeverSlept();
});
