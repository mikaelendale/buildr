<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 2 (plan §8.3): one call writes a one-sentence description per project.
 */
#[MaxTokens(8000)]
class ProjectDescriber implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly string $effort = 'low',
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->loadPrompt('project_describer');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'projects' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'description' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
