<?php

namespace App\Services\Analytics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-party profile views (plan §3 profile_views) and site page visits
 * (page_visits). No raw IPs are stored: uniqueness uses sha256(ip | user
 * agent | daily salt), where the salt lives only in cache for one day, so
 * hashes can't be linked across days.
 */
class ViewTracker
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|curl|wget|python|headless/i';

    public function record(Model $viewable, Request $request): void
    {
        $this->count('profile_views', [
            'viewable_type' => $viewable->getMorphClass(),
            'viewable_id' => $viewable->getKey(),
        ], $request);
    }

    /**
     * A visit to a site page such as the home page.
     */
    public function recordPage(string $path, Request $request): void
    {
        $this->count('page_visits', ['path' => $path], $request);
    }

    /**
     * Every recorded visit to a page, all time.
     */
    public static function pageTotal(string $path): int
    {
        return (int) DB::table('page_visits')->where('path', $path)->sum('views');
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function count(string $table, array $subject, Request $request): void
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::BOT_PATTERN, $agent) === 1) {
            return;
        }

        $date = now()->toDateString();
        $salt = Cache::remember("pv-salt:{$date}", now()->endOfDay(), fn () => Str::random(40));
        $hash = hash('sha256', $request->ip().'|'.$agent.'|'.$salt);
        $key = $subject + ['date' => $date];

        $unique = Cache::add("pv:{$date}:{$table}:".implode(':', $subject).":{$hash}", true, now()->endOfDay());

        DB::table($table)->insertOrIgnore($key + [
            'views' => 0,
            'unique_views' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table($table)->where($key)->update([
            'views' => DB::raw('views + 1'),
            'unique_views' => $unique ? DB::raw('unique_views + 1') : DB::raw('unique_views'),
            'updated_at' => now(),
        ]);
    }
}
