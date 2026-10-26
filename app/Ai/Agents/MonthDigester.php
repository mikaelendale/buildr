<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 4 map (plan §8.5): one month of posts → a ≤120-word digest with cites.
 */
#[CacheInstructions]
#[MaxTokens(8000)]
class MonthDigester implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly string $effort = 'low',
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->loadPrompt('month_digest');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'cites' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
