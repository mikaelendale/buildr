<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property int|null $telegram_id
 * @property string|null $telegram_username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $avatar_path
 * @property UserRole $role
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $bot_can_message
 * @property bool $notify_summary_digest
 * @property string|null $timezone
 * @property Carbon|null $banned_at
 * @property string|null $ban_reason
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'password', 'telegram_id', 'telegram_username', 'first_name', 'last_name',
    'avatar_path', 'role', 'bot_can_message', 'notify_summary_digest', 'timezone', 'banned_at',
    'ban_reason', 'last_login_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'telegram_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'bot_can_message' => 'boolean',
            'notify_summary_digest' => 'boolean',
            'banned_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<Creator, $this>
     */
    public function creator(): HasOne
    {
        return $this->hasOne(Creator::class);
    }

    /**
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * @return HasMany<LoginEvent, $this>
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * Shape shared with the frontend as `auth.user`.
     *
     * @return array<string, mixed>
     */
    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'telegram_username' => $this->telegram_username,
            'avatar' => $this->avatarUrl(),
            'role' => $this->role->value,
            'is_admin' => $this->isAdmin(),
            'two_factor_enabled' => $this->hasConfirmedTwoFactor(),
            'bot_can_message' => $this->bot_can_message,
            'has_creator' => $this->creator !== null,
            'creator_slug' => $this->creator?->slug,
        ];
    }
}
