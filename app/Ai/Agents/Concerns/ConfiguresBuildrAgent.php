<?php

namespace App\Ai\Agents\Concerns;

use App\Services\Ai\AiBudgets;
use App\Services\Ai\Prompts;
use Laravel\Ai\Enums\Lab;

/**
 * Shared knobs for every Buildr agent, per provider: how hard it thinks
 * (Anthropic effort, Groq reasoning effort, Gemini thinking level), output
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
        if ($provider === 'gemini') {
            return [
                'max_output_tokens' => $this->outputTokenBudget(),
                'thinking_level' => match (class_basename($this)) {
                    'ProfileWriter', 'ClaimChecker', 'ProjectResolver' => 'medium',
                    'ProjectDescriber' => 'minimal',
                    default => 'low',
                },
            ];
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
