<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a creator who logged in with Telegram.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'telegram_id' => fake()->unique()->numberBetween(10_000_000, 9_999_999_999),
            'telegram_username' => Str::lower($first).fake()->unique()->numberBetween(1, 99999),
            'role' => UserRole::Creator,
            'email' => null,
            'password' => null,
            'bot_can_message' => true,
            'last_login_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * An admin account (email + password, no Telegram).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'telegram_id' => null,
            'telegram_username' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function banned(string $reason = 'Spam'): static
    {
        return $this->state(fn (array $attributes) => [
            'banned_at' => now(),
            'ban_reason' => $reason,
        ]);
    }
}
