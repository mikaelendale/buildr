<?php

namespace Database\Factories;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelAvailability;
use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ScrapeStatus;
use App\Enums\TelegramPageType;
use App\Models\Channel;
use App\Models\Creator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => strtolower(fake()->unique()->bothify('????????_###')),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'subscribers' => fake()->numberBetween(100, 50_000),
            'claim_status' => ChannelClaimStatus::Unclaimed,
            'visibility' => ChannelVisibility::Hidden,
            'added_by' => ChannelAddedBy::Admin,
            'availability' => ChannelAvailability::Ok,
            'page_type' => TelegramPageType::Channel,
            'scrape_status' => ScrapeStatus::Idle,
        ];
    }

    public function claimedBy(Creator $creator): static
    {
        return $this->state(fn (array $attributes) => [
            'creator_id' => $creator->id,
            'claim_status' => ChannelClaimStatus::Claimed,
            'added_by' => ChannelAddedBy::Self,
            'visibility' => ChannelVisibility::Hidden,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ChannelVisibility::Published,
            'published_at' => now(),
        ]);
    }
}
