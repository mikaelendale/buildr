<?php

namespace App\Services\Ai;

/**
 * One route the pipeline can send work to: a provider and its model(s), the
 * context budget prompts are built with, a quality tier (1 strong, 2 solid,
 * 3 light) and the route's rate limits.
 */
final class AiLane
{
    /**
     * @param  array<string, string>  $models  kind (extraction, synthesis, verify) => model
     */
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly string $budget,
        public readonly array $models,
        public readonly int $tokensPerMinute = 0,
        public readonly int $requestsPerMinute = 0,
        public readonly int $timeout = 600,
        public readonly int $tier = 1,
        public readonly int $requestsPerDay = 0,
        /** Whether the model takes a thinking level (Gemma on the Gemini API doesn't). */
        public readonly bool $thinking = true,
    ) {}

    public function model(string $kind): string
    {
        return $this->models[$kind] ?? $this->models['synthesis'] ?? array_values($this->models)[0] ?? '';
    }

    /**
     * A value from this lane's budget profile (config/buildr.php ai.budgets).
     */
    public function int(string $key): int
    {
        return (int) config("buildr.ai.budgets.{$this->budget}.{$key}");
    }
}
