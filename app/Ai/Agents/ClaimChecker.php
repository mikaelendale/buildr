<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use App\Enums\SummaryMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 5 LLM check (plan §8.7): each claim + its cited posts →
 * supported | unsupported | sensitive. A separate call from the writer.
 */
#[MaxTokens(8000)]
class ClaimChecker implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly SummaryMode $mode,
        public readonly string $effort = 'medium',
    ) {}

    public function instructions(): Stringable|string
    {
        $unclaimedRule = $this->mode === SummaryMode::Unclaimed
            ? ', or names, guesses at, or describes personal traits of the person who runs the channel (the channel is unclaimed)'
            : '';

        return str_replace('{{UNCLAIMED_RULE}}', $unclaimedRule, $this->loadPrompt('verifier'));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdicts' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'verdict' => $schema->string()->enum(['supported', 'unsupported', 'sensitive'])->required(),
                'reason' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
