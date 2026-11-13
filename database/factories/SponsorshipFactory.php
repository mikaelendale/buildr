<?php

namespace Database\Factories;

use App\Enums\SponsorshipStatus;
use App\Models\Channel;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsorship>
 */
class SponsorshipFactory extends Factory
{
    /**
     * Define the model's default state: a request waiting on an admin.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'requested_by' => User::factory(),
            'status' => SponsorshipStatus::Pending,
            'review_note' => null,
            'reviewed_at' => null,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * A request waiting on an admin decision.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SponsorshipStatus::Pending]);
    }

    /**
     * An approved, currently active sponsorship. `starts_at` defaults to now
     * and `ends_at` to null (indefinite), matching how a promotion activates.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SponsorshipStatus::Approved,
            'starts_at' => now(),
            'ends_at' => null,
        ]);
    }

    /**
     * An approved sponsorship whose window has already closed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SponsorshipStatus::Approved,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function rejected(string $note = 'Not a fit right now.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SponsorshipStatus::Rejected,
            'review_note' => $note,
            'reviewed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => SponsorshipStatus::Cancelled]);
    }
}
