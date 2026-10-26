<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Loads versioned prompt files from resources/prompts/v{prompt_version}.
 * Changing a prompt means bumping BUILDR_AI_PROMPT_VERSION, which also makes
 * extraction re-run (it is idempotent per post + prompt_version).
 *
 * A provider can have its own wording of a prompt in
 * resources/prompts/v{n}/{provider}/{name}.md (same rules, phrased for how
 * that model reads instructions); it is used while a step runs on that
 * provider's lane.
 */
class Prompts
{
    /**
     * @var array<string, string>
     */
    private array $loaded = [];

    public function version(): int
    {
        return (int) config('buildr.ai.prompt_version');
    }

    public function get(string $name): string
    {
        $provider = AiLanes::active()?->provider;
        $variant = $provider !== null ? resource_path("prompts/v{$this->version()}/{$provider}/{$name}.md") : null;
        $path = $variant !== null && is_file($variant) ? $variant : resource_path("prompts/v{$this->version()}/{$name}.md");
        $key = $this->version().'/'.$path;

        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        if (! is_file($path)) {
            throw new RuntimeException("Prompt [{$this->version()}/{$name}] not found.");
        }

        $content = (string) file_get_contents($path);
        $content = trim((string) preg_replace('/<!--.*?-->/s', '', $content));

        return $this->loaded[$key] = $content;
    }

    /**
     * The user message for a step. Gemini reads long inputs best with the
     * data first, wrapped in a tag, and the task last ("Based on the posts
     * above…"); other providers get the task first, as before.
     */
    public function message(string $data, string $tag, string $task, ?string $closing = null): string
    {
        if (AiLanes::active()?->provider === 'gemini') {
            return "<{$tag}>\n{$data}\n</{$tag}>\n\n".($closing ?? $task);
        }

        return trim($task."\n\n".$data);
    }

    /**
     * The voice examples, cut to the first $count "### Example" sections.
     */
    public function examples(int $count): string
    {
        $parts = preg_split('/^(?=### Example)/m', $this->get('examples')) ?: [];
        $intro = str_starts_with((string) ($parts[0] ?? ''), '### Example') ? '' : (string) array_shift($parts);

        return trim($intro.implode('', array_slice($parts, 0, max(1, $count))));
    }
}
