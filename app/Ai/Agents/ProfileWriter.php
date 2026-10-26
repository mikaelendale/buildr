<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresBuildrAgent;
use App\Enums\SummaryMode;
use App\Services\Ai\AiBudgets;
use App\Services\Ai\Prompts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 4 reduce (plan §8.5–8.6): month digests + stats + projects + top posts
 * (+ the creator's own fields in claimed mode) → the profile JSON.
 */
#[CacheInstructions]
#[MaxTokens(16000)]
class ProfileWriter implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresBuildrAgent, Promptable;

    public function __construct(
        public readonly string $model,
        public readonly SummaryMode $mode,
        public readonly string $effort = 'medium',
    ) {}

    public function instructions(): Stringable|string
    {
        return str_replace(
            ['{{MODE_RULES}}', '{{EXAMPLES}}'],
            [$this->loadPrompt('mode_'.$this->mode->value), app(Prompts::class)->examples(AiBudgets::int('writer_examples'))],
            $this->loadPrompt('profile_writer'),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        $cites = fn () => $schema->array()->items($schema->integer())->required();

        return [
            'hook' => $schema->string()->required(),
            'hook_cites' => $cites(),
            'vibe_tags' => $schema->array()->items($schema->string())->required(),
            'intro' => $schema->string()->required(),
            'intro_cites' => $cites(),
            'building_now' => $schema->array()->items($schema->object([
                'project_slug' => $schema->string()->required(),
                'line' => $schema->string()->required(),
                'cites' => $cites(),
            ])->withoutAdditionalProperties())->required(),
            'eras' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'from' => $schema->string()->required(),
                'to' => $schema->string()->required(),
                'story' => $schema->string()->required(),
                'cites' => $cites(),
            ])->withoutAdditionalProperties())->required(),
            'start_here' => $schema->array()->items($schema->object([
                'msg_id' => $schema->integer()->required(),
                'why' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'in_their_words' => $schema->array()->items($schema->object([
                'msg_id' => $schema->integer()->required(),
                'quote' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'how_they_work' => $schema->object([
                'text' => $schema->string()->required(),
                'cites' => $cites(),
            ])->withoutAdditionalProperties()->nullable()->required(),
            'fun_facts' => $schema->array()->items($schema->object([
                'text' => $schema->string()->required(),
                'source' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
