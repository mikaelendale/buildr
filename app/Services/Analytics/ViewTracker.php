<?php

namespace App\Services\Analytics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-party profile views (plan §3 profile_views). No raw IPs are stored:
 * uniqueness uses sha256(ip | user agent | daily salt), where the salt lives
 * only in cache for one day, so hashes can't be linked across days.
 */
class ViewTracker
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|curl|wget|python|headless/i';

    public function record(Model $viewable, Request $request): void
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::BOT_PATTERN, $agent) === 1) {
            return;
        }

        $date = now()->toDateString();
        $salt = Cache::remember("pv-salt:{$date}", now()->endOfDay(), fn () => Str::random(40));
        $hash = hash('sha256', $request->ip().'|'.$agent.'|'.$salt);
        $key = [
            'viewable_type' => $viewable->getMorphClass(),
            'viewable_id' => $viewable->getKey(),
            'date' => $date,
        ];

        $unique = Cache::add("pv:{$date}:{$key['viewable_type']}:{$key['viewable_id']}:{$hash}", true, now()->endOfDay());

        DB::table('profile_views')->insertOrIgnore($key + [
            'views' => 0,
            'unique_views' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('profile_views')->where($key)->update([
            'views' => DB::raw('views + 1'),
            'unique_views' => $unique ? DB::raw('unique_views + 1') : DB::raw('unique_views'),
            'updated_at' => now(),
        ]);
    }
}
