<?php

namespace App\Services\Ai;

use Closure;

/**
 * Per-call context limits (config/buildr.php ai.budgets). While a step runs
 * on a lane, that lane's profile is active; otherwise the configured one.
 * "compact" fits providers with small per-minute token limits.
 */
final class AiBudgets
{
    private static ?string $active = null;

    public static function configuredProfile(): string
    {
        $profile = (string) config('buildr.ai.budget_profile', 'standard');

        return is_array(config("buildr.ai.budgets.{$profile}")) ? $profile : 'standard';
    }

    public static function profile(): string
    {
        return self::$active !== null && is_array(config('buildr.ai.budgets.'.self::$active))
            ? self::$active
            : self::configuredProfile();
    }

    public static function int(string $key): int
    {
        return (int) config('buildr.ai.budgets.'.self::profile().'.'.$key);
    }

    /**
     * Output token cap for one kind of call (extraction, digest, writer, verify, describe).
     */
    public static function maxOutput(string $kind): int
    {
        return self::int('max_output_tokens.'.$kind) ?: 4000;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(string $profile, Closure $callback): mixed
    {
        $previous = self::$active;
        self::$active = $profile;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }
}
