<?php

namespace Tests\Support;

use App\Ai\Agents\ClaimChecker;
use App\Ai\Agents\MonthDigester;
use App\Ai\Agents\PostExtractor;
use App\Ai\Agents\ProfileWriter;
use App\Ai\Agents\ProjectDescriber;
use App\Ai\Agents\ProjectResolver;
use Closure;

/**
 * Fakes every Buildr agent with responses derived from the prompt payload, so
 * the whole pipeline can run end-to-end without network calls.
 */
trait FakesAi
{
    /**
     * @param  array<string, string>  $verdictOverrides  claim id → verdict
     */
    protected function fakeAi(array $verdictOverrides = [], ?Closure $writer = null): void
    {
        PostExtractor::fake(function (string $prompt) {
            $posts = $this->payload($prompt);

            return ['posts' => array_map(fn (array $p) => [
                'msg_id' => $p['msg_id'],
                'type' => str_contains($p['text'], 'Launched') ? 'project_launch' : 'progress_update',
                'topics' => ['Laravel'],
                'projects' => str_contains($p['text'], 'Chapa Bot') ? [['name' => 'Chapa Bot', 'role' => 'own']] : [],
                'tools' => ['Laravel'],
                'milestone' => str_contains($p['text'], 'Launched') ? 'launched v1' : null,
                'tone' => 'hype',
                'is_personal_sensitive' => str_contains($p['text'], 'my health'),
            ], $posts)];
        });

        ProjectDescriber::fake(fn (string $prompt) => [
            'projects' => array_map(
                fn (array $p) => ['key' => $p['key'], 'description' => "{$p['name']} is a payments bot for Telegram shops."],
                $this->payload($prompt)['projects'],
            ),
        ]);

        // One project per candidate, named after its first spelling.
        ProjectResolver::fake(fn (string $prompt) => [
            'projects' => array_map(fn (array $c) => [
                'members' => [$c['key']],
                'name' => $c['names'][0],
                'status' => 'launched',
                'url' => $c['urls'][0] ?? null,
                'description' => "{$c['names'][0]} is a payments bot for Telegram shops.",
                'history' => null,
            ], $this->payload($prompt)['candidates']),
            'dropped' => [],
        ]);

        MonthDigester::fake(function (string $prompt) {
            $data = $this->payload($prompt);

            return [
                'summary' => "In {$data['month']} the channel kept shipping Chapa Bot updates.",
                'cites' => array_slice(array_column($data['posts'], 'msg_id'), 0, 3),
            ];
        });

        ProfileWriter::fake($writer ?? function (string $prompt) {
            $input = $this->payload($prompt);
            $posts = $input['posts'];
            $first = $posts[0];
            $quote = implode(' ', array_slice(explode(' ', $first['text']), 0, 5));
            $project = $input['projects'][0]['slug'] ?? 'missing';

            return [
                'hook' => 'Ships Chapa Bot updates every single week.',
                'hook_cites' => [$posts[0]['msg_id']],
                'vibe_tags' => ['Builder', 'Shipper'],
                'intro' => 'This channel documents building Chapa Bot in public.',
                'intro_cites' => [$posts[1]['msg_id']],
                'building_now' => [['project_slug' => $project, 'line' => 'Payments for Telegram shops.', 'cites' => [$posts[0]['msg_id']]]],
                'eras' => [['name' => 'The Bot Era', 'from' => '2026-01', 'to' => '2026-03', 'story' => 'Chapa Bot went from idea to launch.', 'cites' => [$posts[2]['msg_id']]]],
                'start_here' => [['msg_id' => $posts[0]['msg_id'], 'why' => 'The launch post.']],
                'in_their_words' => [['msg_id' => $first['msg_id'], 'quote' => $quote]],
                'how_they_work' => ['text' => 'Laravel every day.', 'cites' => [$posts[0]['msg_id']]],
                'fun_facts' => [['text' => "Posted {$input['stats']['total_posts']} times so far.", 'source' => 'stats.total_posts']],
            ];
        });

        ClaimChecker::fake(function (string $prompt) use ($verdictOverrides) {
            $claims = $this->payload($prompt)['claims'];

            return ['verdicts' => array_map(fn (array $c) => [
                'id' => $c['id'],
                'verdict' => $verdictOverrides[$c['id']] ?? 'supported',
                'reason' => isset($verdictOverrides[$c['id']]) ? 'Test override' : null,
            ], $claims)];
        });
    }

    /**
     * The JSON payload the services append to their prompts.
     *
     * @return array<string, mixed>
     */
    protected function payload(string $prompt): array
    {
        $start = strpos($prompt, '{');
        $startArray = strpos($prompt, '[');

        if ($startArray !== false && ($start === false || $startArray < $start)) {
            $start = $startArray;
        }

        return (array) json_decode(substr($prompt, (int) $start), true);
    }
}
