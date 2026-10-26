<?php

namespace App\Services\Ai;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Smart routing over a pool of provider + model routes (config/buildr.php
 * ai.routes, for the providers in BUILDR_AI_LANES). Every call picks the
 * route that best fits the step right now:
 *
 * - quality: each step allows some tiers and prefers strong models (writer,
 *   fact-checker, resolver) or light ones (labelling, digests), so strong
 *   quota is saved for the steps that need it;
 * - health: routes that recently overloaded or failed rank lower;
 * - headroom: routes busy this minute, or near their daily request cap,
 *   rank lower; routes out of quota or cooling down sit out;
 * - spread: equally good routes take turns.
 *
 * A route that fails hands the call to the next best (AiRunner::run()).
 * Without BUILDR_AI_LANES there is one lane from the classic settings, with
 * its fallback models.
 */
class AiLanes
{
    /** Which model kind each step uses on a classic lane. */
    public const ROLES = [
        'extraction' => 'extraction',
        'describe' => 'extraction',
        'digest' => 'extraction',
        'resolve' => 'synthesis',
        'writer' => 'synthesis',
        'verify' => 'verify',
    ];

    /** Tiers each step may use, and whether it prefers strong or light models. */
    private const POLICY = [
        'extraction' => ['tiers' => [3, 2], 'prefer' => 'light'],
        'describe' => ['tiers' => [3, 2], 'prefer' => 'light'],
        'digest' => ['tiers' => [3, 2], 'prefer' => 'light'],
        'resolve' => ['tiers' => [1, 2], 'prefer' => 'strong'],
        'writer' => ['tiers' => [1, 2], 'prefer' => 'strong'],
        'verify' => ['tiers' => [1, 2], 'prefer' => 'strong'],
    ];

    /** Routes scoring within this much of the best take turns. */
    private const TIE = 3.0;

    private static ?AiLane $active = null;

    /**
     * @return non-empty-list<AiLane>
     */
    public function all(): array
    {
        if (! $this->routing()) {
            return [$this->classic()];
        }

        $providers = (array) config('buildr.ai.lanes', []);
        $lanes = [];

        foreach ((array) config('buildr.ai.routes', []) as $route) {
            if (! is_array($route) || ! in_array($route['provider'] ?? null, $providers, true) || ! is_string($route['model'] ?? null)) {
                continue;
            }

            $model = $route['model'];

            $lanes[] = new AiLane(
                name: $route['provider'].':'.$model,
                provider: (string) $route['provider'],
                budget: (string) ($route['budget'] ?? 'standard'),
                models: ['extraction' => $model, 'synthesis' => $model, 'verify' => $model],
                tokensPerMinute: (int) ($route['tpm'] ?? 0),
                requestsPerMinute: (int) ($route['rpm'] ?? 0),
                timeout: (int) ($route['timeout'] ?? config('buildr.ai.timeout_seconds')),
                tier: (int) ($route['tier'] ?? 2),
                requestsPerDay: (int) ($route['rpd'] ?? 0),
                thinking: (bool) ($route['thinking'] ?? true),
            );
        }

        return $lanes !== [] ? $lanes : [$this->classic()];
    }

    /**
     * Routing is on when providers are listed (BUILDR_AI_LANES).
     */
    public function routing(): bool
    {
        return (array) config('buildr.ai.lanes', []) !== [];
    }

    /**
     * The single lane from the classic settings.
     */
    public function classic(): AiLane
    {
        $profile = AiBudgets::configuredProfile();

        return new AiLane(
            name: 'default',
            provider: (string) config('buildr.ai.provider'),
            budget: $profile,
            models: [
                'extraction' => (string) config('buildr.ai.extraction_model'),
                'synthesis' => (string) config('buildr.ai.synthesis_model'),
                'verify' => (string) config('buildr.ai.verify_model'),
            ],
            tokensPerMinute: (int) config("buildr.ai.budgets.{$profile}.tokens_per_minute"),
            timeout: (int) config('buildr.ai.timeout_seconds'),
        );
    }

    /**
     * The lane a model belongs to (directly or through its fallbacks).
     */
    public function forModel(string $model): AiLane
    {
        $lanes = $this->all();

        foreach ($lanes as $lane) {
            foreach ($lane->models as $own) {
                if (in_array($model, $this->chain($own), true)) {
                    return $lane;
                }
            }
        }

        return $lanes[0];
    }

    public static function kind(string $role): string
    {
        return self::ROLES[$role] ?? 'synthesis';
    }

    /**
     * The best route for a step right now, or null when none can take it.
     *
     * @param  list<string>  $exclude  routes already tried for this call
     */
    public function pick(string $role, array $exclude = [], bool $advance = true): ?AiLane
    {
        $candidates = array_values(array_filter(
            $this->all(),
            fn (AiLane $lane) => ! in_array($lane->name, $exclude, true) && $this->usable($lane, $role),
        ));

        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        $scored = collect($candidates)
            ->map(fn (AiLane $lane) => ['lane' => $lane, 'score' => $this->score($lane, $role)])
            ->sortBy('score')
            ->values();

        $best = (float) $scored[0]['score'];
        $ties = $scored->filter(fn (array $s) => $s['score'] <= $best + self::TIE)->values();

        if ($ties->count() === 1) {
            return $ties[0]['lane'];
        }

        $key = 'ai-lanes:turn:'.$role;

        if ($advance) {
            Cache::add($key, 0, now()->addDay());
            $turn = (int) Cache::increment($key);
        } else {
            $turn = (int) Cache::get($key, 0) + 1;
        }

        return $ties[($turn - 1) % $ties->count()]['lane'];
    }

    /**
     * Lower is better: tier fit first, then recent health, minute headroom
     * and how much of the day's request cap is used.
     */
    public function score(AiLane $lane, string $role): float
    {
        if (! $this->routing()) {
            return 0.0;
        }

        $policy = self::POLICY[$role] ?? self::POLICY['writer'];
        $tierRank = $policy['prefer'] === 'light' ? 3 - $lane->tier : $lane->tier - 1;
        $model = $lane->model(self::kind($role));

        return $tierRank * 10
            + $this->health($lane->provider, $model) * 8
            + $this->minuteLoad($lane, $model) * 4
            + ($lane->requestsPerDay > 0 ? $this->usedToday($lane->provider, $model) / $lane->requestsPerDay * 3 : 0);
    }

    public function usable(AiLane $lane, string $role): bool
    {
        if ($this->routing()) {
            $policy = self::POLICY[$role] ?? self::POLICY['writer'];
            $model = $lane->model(self::kind($role));

            return in_array($lane->tier, $policy['tiers'], true)
                && filled(config("ai.providers.{$lane->provider}.key"))
                && $this->blockedFor($lane->provider, $model) === null
                && ($lane->requestsPerDay <= 0 || $this->usedToday($lane->provider, $model) < $lane->requestsPerDay);
        }

        return $this->usableModel($lane, self::kind($role)) !== null;
    }

    /**
     * The lane's model for this kind of step, or its first fallback with quota.
     */
    public function usableModel(AiLane $lane, string $kind): ?string
    {
        foreach ($this->chain($lane->model($kind)) as $model) {
            if ($this->blockedFor($lane->provider, $model) === null) {
                return $model;
            }
        }

        return null;
    }

    /**
     * A model followed by its fallbacks, each once. Routes have none: the
     * router itself moves to the next best route.
     *
     * @return list<string>
     */
    public function chain(string $model): array
    {
        if ($this->routing()) {
            return [$model];
        }

        // Model names contain dots ("gemini-3.5-flash"), so no dot-notation lookups.
        $fallbacks = (array) config('buildr.ai.fallback_models', []);
        $chain = [$model];

        while (is_string($next = $fallbacks[$model] ?? null) && $next !== '' && ! in_array($next, $chain, true)) {
            $chain[] = $model = $next;
        }

        return $chain;
    }

    /**
     * Take a model out of rotation: until its daily quota resets, or for a
     * short cool-down after a per-minute limit or an overload.
     */
    public function block(string $provider, string $model, int $seconds, bool $daily): void
    {
        $seconds = max(1, $seconds);

        Cache::put(
            $daily ? self::exhaustedKey($provider, $model) : self::coolingKey($provider, $model),
            now()->addSeconds($seconds)->getTimestamp(),
            $seconds,
        );
    }

    /**
     * Seconds until the model can be used again, or null when it is free.
     */
    public function blockedFor(string $provider, string $model): ?int
    {
        $until = null;

        foreach ([self::exhaustedKey($provider, $model), self::coolingKey($provider, $model)] as $key) {
            $value = Cache::get($key);

            if ($value === null) {
                continue;
            }

            $seconds = is_int($value) ? max(1, $value - now()->getTimestamp()) : 60;
            $until = max($until ?? 0, $seconds);
        }

        return $until;
    }

    /**
     * Record how a call went: health is a moving failure rate (0 healthy,
     * 1 failing), and every attempt counts against the day's requests.
     */
    public function record(string $provider, string $model, bool $ok): void
    {
        $key = "ai-health:{$provider}:{$model}";
        Cache::put($key, round($this->health($provider, $model) * 0.7 + ($ok ? 0 : 0.3), 4), now()->addHours(2));

        $day = self::dayKey($provider, $model);
        Cache::add($day, 0, now()->addDays(2));
        Cache::increment($day);
    }

    public function health(string $provider, string $model): float
    {
        return (float) Cache::get("ai-health:{$provider}:{$model}", 0.0);
    }

    public function usedToday(string $provider, string $model): int
    {
        return (int) Cache::get(self::dayKey($provider, $model), 0);
    }

    /**
     * How full this minute is for the route (requests or tokens, whichever
     * is closer to its limit); 1 means a call would have to wait.
     */
    public function minuteLoad(AiLane $lane, string $model): float
    {
        $now = now()->getTimestampMs() / 1000;
        $window = array_filter((array) Cache::get(self::windowKey($lane->provider, $model), []), fn (array $e) => $e[0] > $now - 60);

        $requests = $lane->requestsPerMinute > 0 ? count($window) / $lane->requestsPerMinute : 0;
        $tokens = $lane->tokensPerMinute > 0 ? array_sum(array_column($window, 1)) / $lane->tokensPerMinute : 0;

        return min(1.0, max($requests, $tokens));
    }

    /**
     * When the first route frees up for this step (for rescheduling).
     */
    public function soonest(string $role): int
    {
        $waits = [];

        foreach ($this->all() as $lane) {
            foreach ($this->chain($lane->model(self::kind($role))) as $model) {
                $waits[] = $this->blockedFor($lane->provider, $model) ?? 0;
            }
        }

        return max(30, $waits === [] ? 60 : min($waits));
    }

    /**
     * A budget value that fits every route (0 means "no limit" and wins).
     */
    public function widest(string $key): int
    {
        $values = array_map(fn (AiLane $lane) => $lane->int($key), $this->all());

        return in_array(0, $values, true) ? 0 : max($values);
    }

    /**
     * Every provider's models with their state, for the admin panel.
     *
     * @return list<array{name: string, provider: string, models: array<string, array{model: string, tier: int, blocked_for: ?int, daily: bool, health: float, today: int, rpd: int}>}>
     */
    public function status(): array
    {
        $providers = [];

        foreach ($this->all() as $lane) {
            foreach (array_unique($lane->models) as $own) {
                foreach ($this->chain($own) as $model) {
                    $providers[$lane->provider][$model] ??= [
                        'model' => $model,
                        'tier' => $lane->tier,
                        'blocked_for' => $this->blockedFor($lane->provider, $model),
                        'daily' => Cache::has(self::exhaustedKey($lane->provider, $model)),
                        'health' => $this->health($lane->provider, $model),
                        'today' => $this->usedToday($lane->provider, $model),
                        'rpd' => $lane->requestsPerDay,
                    ];
                }
            }
        }

        $status = [];

        foreach ($providers as $provider => $models) {
            $status[] = ['name' => (string) $provider, 'provider' => (string) $provider, 'models' => $models];
        }

        return $status;
    }

    public static function exhaustedKey(string $provider, string $model): string
    {
        return "ai-quota:exhausted:{$provider}:{$model}";
    }

    public static function coolingKey(string $provider, string $model): string
    {
        return "ai-quota:cooling:{$provider}:{$model}";
    }

    public static function windowKey(string $provider, string $model): string
    {
        return "ai-tpm:{$provider}:{$model}";
    }

    private static function dayKey(string $provider, string $model): string
    {
        return "ai-day:{$provider}:{$model}:".now()->toDateString();
    }

    /**
     * The lane a step is running on (prompts pick their provider variant).
     */
    public static function active(): ?AiLane
    {
        return self::$active;
    }

    /**
     * Run $callback with $lane active: its budget profile sizes the prompt
     * and its provider picks the prompt variant.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(AiLane $lane, Closure $callback): mixed
    {
        $previous = self::$active;
        self::$active = $lane;

        try {
            return AiBudgets::using($lane->budget, $callback);
        } finally {
            self::$active = $previous;
        }
    }
}
