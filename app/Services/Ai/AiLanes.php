<?php

namespace App\Services\Ai;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The providers the pipeline rotates between (config/buildr.php ai.lanes).
 * High-volume steps take lanes in turn so free tiers add up; one-shot steps
 * take the first lane with quota left. A model whose daily quota is spent,
 * or that is overloaded, sits out until it is back, and its lane falls back
 * to the next model in its chain (ai.fallback_models).
 *
 * Without configured lanes there is one lane built from the classic
 * settings (BUILDR_AI_PROVIDER, the three models and the budget profile).
 */
class AiLanes
{
    /** Which model each kind of step uses (digests are plain summaries: the cheap model keeps the strong one's quota for the writer). */
    public const ROLES = [
        'extraction' => 'extraction',
        'describe' => 'extraction',
        'digest' => 'extraction',
        'resolve' => 'synthesis',
        'writer' => 'synthesis',
        'verify' => 'verify',
    ];

    private static ?AiLane $active = null;

    /**
     * @return non-empty-list<AiLane>
     */
    public function all(): array
    {
        $lanes = [];

        foreach ((array) config('buildr.ai.lanes', []) as $name) {
            $options = config('buildr.ai.lane_options.'.$name);

            if (! is_array($options)) {
                continue;
            }

            $lanes[] = new AiLane(
                name: (string) $name,
                provider: (string) ($options['provider'] ?? $name),
                budget: (string) ($options['budget'] ?? 'standard'),
                models: array_filter((array) ($options['models'] ?? []), fn ($m) => is_string($m) && $m !== ''),
                tokensPerMinute: (int) ($options['tokens_per_minute'] ?? 0),
                requestsPerMinute: (int) ($options['requests_per_minute'] ?? 0),
                timeout: (int) ($options['timeout_seconds'] ?? config('buildr.ai.timeout_seconds')),
            );
        }

        return $lanes !== [] ? $lanes : [$this->classic()];
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
     * The next lane for a step, or null when none has quota left. "rotate"
     * roles take turns; "prefer" roles use the configured order.
     *
     * @param  list<string>  $exclude  lane names already tried for this step
     */
    public function pick(string $role, array $exclude = [], bool $advance = true): ?AiLane
    {
        $candidates = array_values(array_filter(
            $this->all(),
            fn (AiLane $lane) => ! in_array($lane->name, $exclude, true) && $this->usable($lane, $role),
        ));

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1 || config('buildr.ai.roles.'.$role, 'rotate') !== 'rotate') {
            return $candidates[0];
        }

        $key = 'ai-lanes:turn:'.$role;

        if ($advance) {
            Cache::add($key, 0, now()->addDay());
            $turn = (int) Cache::increment($key);
        } else {
            $turn = (int) Cache::get($key, 0) + 1;
        }

        return $candidates[($turn - 1) % count($candidates)];
    }

    public function usable(AiLane $lane, string $role): bool
    {
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
     * A model followed by its fallbacks, each once.
     *
     * @return list<string>
     */
    public function chain(string $model): array
    {
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
     * When the first lane frees up for this step (for rescheduling).
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
     * A budget value that fits every lane (0 means "no limit" and wins).
     */
    public function widest(string $key): int
    {
        $values = array_map(fn (AiLane $lane) => $lane->int($key), $this->all());

        return in_array(0, $values, true) ? 0 : max($values);
    }

    /**
     * Status of every lane for the admin panel.
     *
     * @return list<array{name: string, provider: string, models: array<string, array{model: string, blocked_for: ?int, daily: bool}>}>
     */
    public function status(): array
    {
        return array_map(fn (AiLane $lane) => [
            'name' => $lane->name,
            'provider' => $lane->provider,
            'models' => collect($lane->models)
                ->flatMap(fn (string $model) => $this->chain($model))
                ->unique()
                ->mapWithKeys(fn (string $model) => [$model => [
                    'model' => $model,
                    'blocked_for' => $this->blockedFor($lane->provider, $model),
                    'daily' => Cache::has(self::exhaustedKey($lane->provider, $model)),
                ]])
                ->all(),
        ], $this->all());
    }

    public static function exhaustedKey(string $provider, string $model): string
    {
        return "ai-quota:exhausted:{$provider}:{$model}";
    }

    public static function coolingKey(string $provider, string $model): string
    {
        return "ai-quota:cooling:{$provider}:{$model}";
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
