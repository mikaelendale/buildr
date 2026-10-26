<?php

namespace App\Services\Ai\Data;

use App\Models\Post;

/**
 * One "I build X" mention found by post extraction.
 */
final readonly class ProjectMention
{
    public function __construct(
        public string $name,
        public string $normalized,
        public Post $post,
        public ?string $milestone,
    ) {}
}
