<?php

namespace Database\Factories;

use App\Enums\PostLang;
use App\Models\Channel;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'channel_id' => Channel::factory(),
            'msg_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'posted_at' => fake()->dateTimeBetween('-1 year'),
            'text' => $text,
            'text_html' => e($text),
            'lang' => PostLang::En,
            'views' => fake()->numberBetween(50, 20_000),
            'has_media' => false,
            'content_hash' => hash('sha256', $text),
            'scraped_at' => now(),
        ];
    }
}
