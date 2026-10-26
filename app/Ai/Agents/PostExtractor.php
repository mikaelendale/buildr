<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use App\Enums\PostTone;
use App\Enums\PostType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 1 (plan §8.2): label a batch of 30–50 posts with type, topics,
 * projects, tools, milestone, tone and a sensitivity flag.
 */
#[CacheInstructions]
#[MaxTokens(16000)]
class PostExtractor implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly string $effort = 'low',
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->loadPrompt('extraction');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'posts' => $schema->array()->items($schema->object([
                'msg_id' => $schema->integer()->required(),
                'type' => $schema->string()->enum(PostType::values())->required(),
                'topics' => $schema->array()->items($schema->string())->required(),
                'projects' => $schema->array()->items($schema->object([
                    'name' => $schema->string()->required(),
                    'role' => $schema->string()->enum(['own', 'mentioned'])->required(),
                ])->withoutAdditionalProperties())->required(),
                'tools' => $schema->array()->items($schema->string())->required(),
                'milestone' => $schema->string()->nullable()->required(),
                'tone' => $schema->string()->enum(PostTone::values())->required(),
                'is_personal_sensitive' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
