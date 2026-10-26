<?php

namespace App\Services\Ai\Data;

use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Mentions that refer to the same project after fuzzy/brand merging.
 */
final class MentionGroup
{
    /**
     * @var list<string>
     */
    public array $names = [];

    /**
     * Brand tokens seen ("ravn" for RAVN, useravn.com, getravn.xyz).
     *
     * @var list<string>
     */
    public array $brands = [];

    /**
     * @var array<int, Post>
     */
    public array $posts = [];

    /**
     * @var list<string>
     */
    public array $milestones = [];

    /**
     * Dated milestones, oldest first ("2026-05: launched v1").
     *
     * @var list<string>
     */
    public array $timeline = [];

    public function __construct(public readonly string $normalized) {}

    public function add(ProjectMention $mention, string $brand = ''): void
    {
        $this->names[] = $mention->name;
        $this->posts[$mention->post->id] = $mention->post;

        if ($brand !== '' && ! in_array($brand, $this->brands, true)) {
            $this->brands[] = $brand;
        }

        if ($mention->milestone !== null) {
            $this->milestones[] = $mention->milestone;
            $this->timeline[] = ($mention->post->posted_at?->format('Y-m') ?? '?').': '.$mention->milestone;
            sort($this->timeline);
        }
    }

    /**
     * The spelling used most often.
     */
    public function name(): string
    {
        $counts = array_count_values($this->names);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @return Collection<int, Post>
     */
    public function postsByDate(): Collection
    {
        return collect(array_values($this->posts))->sortBy('posted_at')->values();
    }
}
