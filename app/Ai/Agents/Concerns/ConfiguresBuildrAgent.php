<?php

namespace App\Ai\Agents\Concerns;

use App\Services\Ai\AiBudgets;
use App\Services\Ai\AiLanes;
use App\Services\Ai\Prompts;
use Laravel\Ai\Enums\Lab;

/**
 * Shared knobs for every Buildr agent, per provider: how hard it thinks
 * (OpenAI and Groq reasoning effort, Anthropic effort, Gemini thinking level), output
 * caps, and the server-side refusal fallback on the models that support it.
 * Agents expose `$model` and `$effort`.
 */
trait ConfiguresBuildrAgent
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        // Groq's gpt-oss models: reasoning effort, and an output cap that keeps
        // prompt + max output under the per-minute token limit. The compact
        // profile keeps the high-volume steps at low effort; the writer and the
        // fact-checker get medium, which they need to respect the mode rules.
        if ($provider === 'groq') {
            $effort = in_array($this->effort, ['low', 'medium', 'high'], true) ? $this->effort : 'high';

            if (AiBudgets::profile() === 'compact') {
                $effort = in_array(class_basename($this), ['ClaimChecker', 'ProfileWriter', 'ProjectResolver'], true) ? 'medium' : 'low';
            }

            return [
                'reasoning_effort' => $effort,
                'max_completion_tokens' => $this->outputTokenBudget(),
            ];
        }

        // Gemini 3: the output cap includes thinking tokens. Labelling and
        // short descriptions need little or no thinking; the writer, the
        // fact-checker and the project resolver weigh evidence, so medium.
        // Some models (Gemma) take no thinking level at all; "low" is the floor
        // every Gemini 3 model accepts.
        if ($provider === 'gemini') {
            return array_filter([
                'max_output_tokens' => $this->outputTokenBudget(),
                'thinking_level' => AiLanes::active()?->thinking === false ? null : match (class_basename($this)) {
                    'ProfileWriter', 'ClaimChecker', 'ProjectResolver' => 'medium',
                    default => 'low',
                },
            ]);
        }

        // OpenAI (Responses API): the output cap (reasoning counts against it
        // and bills as output) and how hard the model thinks. Labelling,
        // digests and descriptions need none; the writer and the project
        // resolver weigh evidence (BUILDR_AI_OPENAI_EFFORT); the fact-checker
        // stays at low.
        if ($provider === 'openai') {
            return array_filter([
                'max_output_tokens' => $this->outputTokenBudget(),
                'reasoning' => self::openAiReasoning($this->model, match (class_basename($this)) {
                    'ProfileWriter', 'ProjectResolver' => (string) config('buildr.ai.openai_effort', 'medium'),
                    'ClaimChecker' => 'low',
                    default => null,
                }),
            ]);
        }

        if ($provider !== 'anthropic') {
            return [];
        }

        $options = ['output_config' => ['effort' => $this->effort]];

        // Opus/Sonnet support `fallbacks: "default"` (beta header set in config/ai.php).
        if (preg_match('/^claude-(opus|sonnet|fable)-/', $this->model) === 1) {
            $options['fallbacks'] = 'default';
        }

        return $options;
    }

    /**
     * Reasoning effort for an OpenAI model ($effort, or the lowest setting
     * when null), or null for models that don't reason (gpt-4.1, gpt-4o).
     * The lowest differs by generation: gpt-5, -mini and -nano take
     * "minimal", gpt-5.1 and later take "none".
     *
     * @return array{effort: string}|null
     */
    public static function openAiReasoning(string $model, ?string $effort): ?array
    {
        if (preg_match('/^(gpt-5|o\d)/', $model) !== 1 || str_contains($model, '-chat')) {
            return null;
        }

        if ($effort !== null) {
            return ['effort' => $effort];
        }

        return ['effort' => preg_match('/^gpt-5(-mini|-nano)?(-\d{4}-\d{2}-\d{2})?$/', $model) === 1 ? 'minimal' : 'none'];
    }

    /**
     * The output cap for this agent under the active budget profile.
     */
    public function outputTokenBudget(): int
    {
        return AiBudgets::maxOutput(match (class_basename($this)) {
            'PostExtractor' => 'extraction',
            'MonthDigester' => 'digest',
            'ProfileWriter' => 'writer',
            'ClaimChecker' => 'verify',
            'ProjectResolver' => 'resolve',
            default => 'describe',
        });
    }

    protected function loadPrompt(string $name): string
    {
        return app(Prompts::class)->get($name);
    }
}
