<?php

namespace Database\Factories;

use App\Enums\CreatorStatus;
use App\Models\Creator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Creator>
 */
class CreatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'user_id' => User::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'display_name' => $name,
            'bio' => fake()->sentence(12),
            'status' => CreatorStatus::Draft,
            'onboarding_step' => 1,
            'timezone' => 'Africa/Addis_Ababa',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CreatorStatus::Published,
            'onboarding_step' => 6,
            'onboarding_completed_at' => now(),
            'submitted_at' => now()->subDay(),
            'published_at' => now(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CreatorStatus::PendingReview,
            'onboarding_step' => 6,
            'onboarding_completed_at' => now(),
            'submitted_at' => now(),
        ]);
    }
}
