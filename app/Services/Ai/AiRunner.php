<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\Exceptions\InvalidAiOutputException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Every AI call goes through here (plan §8.9): budget check first, then the
 * Laravel AI SDK call, then an ai_runs row with tokens and estimated cost.
 *
 * run() spreads steps over the configured lanes (AiLanes): the step builds
 * its prompt for the lane it lands on, and a lane that is out of quota,
 * overloaded or too small for the prompt hands the step to the next one.
 * Within a lane, calls are paced under its tokens/requests per minute, short
 * 429s are waited out, and a spent daily quota moves to the model's fallback.
 * When nothing is left, AiQuotaExhaustedException tells the job when to
 * come back.
 */
class AiRunner
{
    private const MAX_RATE_LIMIT_RETRIES = 4;

    private const MAX_SCHEMA_RETRIES = 3;

    /** Longest provider-requested wait we sit through before giving up (daily caps ask for minutes). */
    private const MAX_RETRY_WAIT_SECONDS = 90;

    /** How long an overloaded or unreachable model sits out. */
    private const COOLDOWN_SECONDS = 120;

    private ?AiLane $lastLane = null;

    private ?string $lastModel = null;

    public function __construct(
        private readonly AiBudget $budget,
        private readonly AiLanes $lanes,
    ) {}

    /**
     * Run one step on the next lane with quota left. $build receives the lane
     * and model (the lane's budget profile is active while it runs) and
     * returns the agent and prompt to send.
     *
     * @param  Closure(AiLane, string): array{0: Agent&HasStructuredOutput, 1: string}  $build
     * @return array<string, mixed>
     */
    public function run(string $role, string $job, Closure $build, ?Model $subject = null, ?AiLane $prefer = null): array
    {
        $tried = [];
        $last = null;

        while (true) {
            $lane = $tried === [] && $prefer !== null && $this->lanes->usable($prefer, $role)
                ? $prefer
                : $this->lanes->pick($role, $tried);

            if ($lane === null) {
                throw $last ?? new AiQuotaExhaustedException("No AI provider has quota left for {$role}.", $this->lanes->soonest($role));
            }

            $tried[] = $lane->name;
            $canHandOff = $this->lanes->pick($role, $tried, advance: false) !== null;

            try {
                return AiLanes::using($lane, function () use ($lane, $role, $job, $build, $subject, $canHandOff, $prefer) {
                    $kind = AiLanes::kind($role);
                    $model = $this->lanes->usableModel($lane, $kind) ?? $lane->model($kind);

                    [$agent, $prompt] = $build($lane, $model);

                    // A prompt bigger than the lane's whole minute can't go through (it was
                    // planned for another lane): hand over, or let the job rebuild it later.
                    $estimate = $this->estimateTokens($agent, $prompt);

                    $plannedHere = $prefer === null || $prefer->name === $lane->name;

                    if (($canHandOff || ! $plannedHere) && $lane->tokensPerMinute > 0 && $estimate > $lane->tokensPerMinute) {
                        throw new AiQuotaExhaustedException("[{$job}] ~{$estimate} tokens is over {$lane->name}'s per-minute limit.", 60);
                    }

                    return $this->call($agent, $prompt, $job, $model, $subject, $lane, $canHandOff);
                });
            } catch (AiQuotaExhaustedException $e) {
                $last = $e;
            }
        }
    }

    /**
     * One call on the lane that owns $model (its fallbacks included).
     *
     * @param  Agent&HasStructuredOutput  $agent
     * @return array<string, mixed>
     */
    public function structured(Agent $agent, string $prompt, string $job, string $model, ?Model $subject = null): array
    {
        $lane = $this->lanes->forModel($model);

        return AiLanes::using($lane, fn () => $this->call($agent, $prompt, $job, $model, $subject, $lane, false));
    }

    /**
     * The lane and model that answered the last successful call.
     */
    public function lastLane(): ?AiLane
    {
        return $this->lastLane;
    }

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    /**
     * @param  Agent&HasStructuredOutput  $agent
     * @return array<string, mixed>
     */
    private function call(Agent $agent, string $prompt, string $job, string $model, ?Model $subject, AiLane $lane, bool $canHandOff): array
    {
        $this->budget->ensureAvailable();

        $started = microtime(true);
        $estimate = $this->estimateTokens($agent, $prompt);

        // A model whose quota is known to be spent (or that is cooling down) goes straight to its fallback.
        if ($this->lanes->blockedFor($lane->provider, $model) !== null) {
            $free = collect($this->lanes->chain($model))->first(fn (string $m) => $this->lanes->blockedFor($lane->provider, $m) === null);

            if ($free === null) {
                throw new AiQuotaExhaustedException("[{$job}] {$lane->name} has no model with quota left.", (int) $this->lanes->blockedFor($lane->provider, $model));
            }

            $model = $free;
        }

        $tried = [$model];

        for ($attempt = 1; ; $attempt++) {
            $this->pace($lane, $model, $estimate);

            try {
                $response = $agent->prompt(
                    $prompt,
                    provider: $lane->provider,
                    model: $model,
                    timeout: $lane->timeout,
                );

                $this->lanes->record($lane->provider, $model, true);

                break;
            } catch (RateLimitedException $e) {
                $this->lanes->record($lane->provider, $model, false);
                $wait = $this->retryAfter($e);
                $daily = $this->isDailyLimit($e, $wait);

                // Short per-minute waits: sit them out, unless another lane can take the step now.
                if (! $daily && ! $canHandOff && $wait !== null && $wait <= self::MAX_RETRY_WAIT_SECONDS && $attempt < self::MAX_RATE_LIMIT_RETRIES) {
                    Sleep::for($wait + 1)->seconds();

                    continue;
                }

                $this->log($job, $model, $subject, null, 'error', $this->describe($e), $started);

                $wait ??= $daily ? $this->secondsUntilDailyReset($lane) : 60;
                $this->lanes->block($lane->provider, $model, $wait, $daily);

                // Daily caps are per model: move to a fallback with its own quota.
                if (($next = $this->nextModel($lane, $tried)) !== null) {
                    $model = $tried[] = $next;
                    $attempt = 0;

                    continue;
                }

                throw new AiQuotaExhaustedException($this->describe($e), max(30, $wait), $e);
            } catch (ProviderOverloadedException|ProviderConnectionException $e) {
                // "High demand" and timeouts pass; let the model sit out a little.
                $this->lanes->record($lane->provider, $model, false);
                $this->log($job, $model, $subject, null, 'error', $this->describe($e), $started);
                $this->lanes->block($lane->provider, $model, self::COOLDOWN_SECONDS, false);

                if (($next = $this->nextModel($lane, $tried)) !== null) {
                    $model = $tried[] = $next;
                    $attempt = 0;

                    continue;
                }

                throw new AiQuotaExhaustedException($this->describe($e), self::COOLDOWN_SECONDS, $e);
            } catch (Throwable $e) {
                $this->log($job, $model, $subject, null, 'error', $this->describe($e), $started);

                // Smaller models sometimes emit JSON that fails the provider's schema
                // check (HTTP 400). A fresh sample usually passes; don't fail the step.
                if (self::isSchemaFailure($e) && $attempt < self::MAX_SCHEMA_RETRIES) {
                    continue;
                }

                if (self::isSchemaFailure($e)) {
                    throw new InvalidAiOutputException("[{$job}] output failed the schema check {$attempt} times.", 0, $e);
                }

                throw $e;
            }
        }

        $data = $response instanceof StructuredAgentResponse ? $response->toArray() : null;

        if (! is_array($data) || $data === []) {
            $this->log($job, $model, $subject, $response->usage, 'invalid', 'Empty or non-structured response', $started);

            throw new InvalidAiOutputException("[{$job}] returned no structured output.");
        }

        $this->log($job, $model, $subject, $response->usage, 'ok', null, $started);
        $this->lastLane = $lane;
        $this->lastModel = $model;

        return $data;
    }

    /**
     * @param  list<string>  $tried
     */
    private function nextModel(AiLane $lane, array $tried): ?string
    {
        foreach ($this->lanes->chain($tried[0]) as $model) {
            if (! in_array($model, $tried, true) && $this->lanes->blockedFor($lane->provider, $model) === null) {
                return $model;
            }
        }

        return null;
    }

    private static function isSchemaFailure(Throwable $e): bool
    {
        if (! $e instanceof RequestException || $e->response === null || $e->response->status() !== 400) {
            return false;
        }

        $body = mb_strtolower($e->response->body());

        return str_contains($body, 'json_validate_failed') || str_contains($body, 'failed to validate json');
    }

    /**
     * The cache key that marks a model's daily quota as spent.
     */
    public static function exhaustedKey(string $model, ?string $provider = null): string
    {
        return AiLanes::exhaustedKey($provider ?? (string) config('buildr.ai.provider'), $model);
    }

    /**
     * USD for one call. $input includes cached tokens and $output includes
     * reasoning tokens (both billed as the SDK reports them). Unknown models
     * are priced high on purpose, so the daily budget errs on the safe side.
     */
    public function estimateCost(string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $price = self::price($model) ?? ['input' => 4.0, 'output' => 20.0, 'cache_read' => 0.4];

        $uncached = max(0, $input - $cacheRead - $cacheWrite);

        return (
            $uncached * $price['input']
            + $cacheWrite * $price['input'] * 1.25
            + $cacheRead * $price['cache_read']
            + $output * $price['output']
        ) / 1_000_000;
    }

    /**
     * List price per million tokens, also for dated snapshots
     * ("gpt-5-nano-2025-08-07" costs what "gpt-5-nano" does).
     *
     * @return array{input: float, output: float, cache_read: float}|null
     */
    public static function price(string $model): ?array
    {
        // Model names contain dots ("gpt-5.4-nano"), so no dot-notation lookup.
        $pricing = (array) config('buildr.ai.pricing', []);

        return $pricing[$model] ?? $pricing[(string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model)] ?? null;
    }

    /**
     * Rough prompt size (~4 chars per token, schema included) plus the output cap,
     * which providers like Groq count against the per-minute limit up front.
     */
    private function estimateTokens(Agent $agent, string $prompt): int
    {
        $chars = mb_strlen((string) $agent->instructions()) + mb_strlen($prompt);

        $output = method_exists($agent, 'outputTokenBudget') ? (int) $agent->outputTokenBudget() : AiBudgets::maxOutput('describe');

        return (int) ceil($chars / 4) + 300 + $output;
    }

    /**
     * Wait until this request fits in the lane's rolling one-minute window
     * (tokens and request count, per model).
     */
    private function pace(AiLane $lane, string $model, int $tokens): void
    {
        $tpm = $lane->tokensPerMinute;
        $rpm = $lane->requestsPerMinute;

        if ($tpm <= 0 && $rpm <= 0) {
            return;
        }

        $key = AiLanes::windowKey($lane->provider, $model);

        while (true) {
            $now = now()->getTimestampMs() / 1000;
            /** @var list<array{0: float, 1: int}> $window */
            $window = array_values(array_filter(
                (array) Cache::get($key, []),
                fn (array $entry) => $entry[0] > $now - 60,
            ));
            $used = array_sum(array_column($window, 1));

            $fitsTokens = $tpm <= 0 || $window === [] || $used + $tokens <= $tpm;
            $fitsRequests = $rpm <= 0 || count($window) < $rpm;

            if ($fitsTokens && $fitsRequests) {
                $window[] = [$now, $tokens];
                Cache::put($key, $window, 120);

                return;
            }

            // Sleep until the oldest entry leaves the window.
            Sleep::for((int) ceil(($window[0][0] + 60 - $now) * 1000) + 250)->milliseconds();
        }
    }

    private function retryAfter(RateLimitedException $e): ?int
    {
        $previous = $e->getPrevious();

        if (! $previous instanceof RequestException || $previous->response === null) {
            return null;
        }

        $header = $previous->response->header('Retry-After');
        $seconds = is_numeric($header) ? (float) $header : null;
        $message = (string) $previous->response->json('error.message', '');

        // OpenAI: "Please try again in 120ms".
        if ($seconds === null && preg_match('/try again in ([\d.]+)ms\b/', $message, $m) === 1) {
            $seconds = max(1.0, (float) $m[1] / 1000);
        }

        // Groq and OpenAI: "Please try again in 7.66s" / "in 13m13.5s" / "in 1h2m3s".
        if ($seconds === null && preg_match('/try again in (?:(\d+)h)?(?:(\d+)m)?([\d.]+)s/', $message, $m) === 1) {
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
        }

        // Gemini: "Please retry in 37.94s", or a retryDelay ("37s") in the error details.
        if ($seconds === null && preg_match('/retry in ([\d.]+)s/i', $message, $m) === 1) {
            $seconds = (float) $m[1];
        }

        if ($seconds === null) {
            foreach ((array) $previous->response->json('error.details', []) as $detail) {
                if (is_array($detail) && preg_match('/^([\d.]+)s$/', (string) ($detail['retryDelay'] ?? ''), $m) === 1) {
                    $seconds = (float) $m[1];
                }
            }
        }

        // Gemini per-minute limits without a delay: the window turns over within a minute.
        if ($seconds === null && preg_match('/per minute/i', $message) === 1) {
            $seconds = 60;
        }

        return $seconds !== null ? (int) ceil($seconds) : null;
    }

    private function isDailyLimit(RateLimitedException $e, ?int $wait): bool
    {
        if ($wait !== null && $wait > self::MAX_RETRY_WAIT_SECONDS) {
            return true;
        }

        $previous = $e->getPrevious();
        $message = $previous instanceof RequestException ? (string) $previous->response->body() : '';

        // OpenAI's "insufficient_quota": the account is out of credit, which no
        // per-minute wait fixes; hold off like a spent daily cap.
        return preg_match('/per ?day|perday|daily|insufficient_quota/i', $message) === 1;
    }

    /**
     * Gemini's free-tier daily quotas reset at midnight Pacific time; others say when.
     */
    private function secondsUntilDailyReset(AiLane $lane): int
    {
        if ($lane->provider !== 'gemini') {
            return 3600;
        }

        $now = Carbon::now('America/Los_Angeles');

        return (int) max(60, $now->diffInSeconds($now->copy()->addDay()->startOfDay()));
    }

    private function describe(Throwable $e): string
    {
        $previous = $e->getPrevious();
        $detail = $previous instanceof RequestException ? (string) $previous->response->json('error.message', '') : '';

        return trim($e->getMessage().' '.$detail);
    }

    private function log(string $job, string $model, ?Model $subject, ?TextUsage $usage, string $status, ?string $error, float $started): void
    {
        $input = $usage->inputTokens ?? 0;
        $output = $usage->outputTokens ?? 0;

        AiRun::create([
            'job' => $job,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd_est' => $this->estimateCost($model, $input, $output, $usage->cacheReadInputTokens ?? 0, $usage->cacheWriteInputTokens ?? 0),
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
