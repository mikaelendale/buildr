<?php

namespace App\Services\Ai;

/**
 * One provider the pipeline can send work to: its models per kind of call,
 * the context budget its prompts are built with, and its rate limits.
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
