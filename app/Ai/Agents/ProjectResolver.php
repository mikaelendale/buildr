<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use App\Enums\ProjectStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 2 (plan §8.3, reworked): reads a squeezed dossier per candidate
 * project and returns the author's real projects. Renames, domain moves and
 * pivots merge into one project with a short history; third-party tools,
 * models and one-off titles are dropped. Status, URL and description follow
 * the latest evidence. It also writes the descriptions, so no second call.
 */
#[MaxTokens(8000)]
class ProjectResolver implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly string $effort = 'medium',
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->loadPrompt('project_resolver');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'projects' => $schema->array()->items($schema->object([
                'members' => $schema->array()->items($schema->string())->required(),
                'name' => $schema->string()->required(),
                'status' => $schema->string()->enum(ProjectStatus::values())->required(),
                'url' => $schema->string()->nullable()->required(),
                'description' => $schema->string()->required(),
                'history' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
            'dropped' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
