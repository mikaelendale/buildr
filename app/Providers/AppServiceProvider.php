<?php

namespace App\Providers;

use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Project;
use App\Models\Report;
use App\Models\Summary;
use App\Models\User;
use App\Services\Search\PostgresSearch;
use App\Services\Search\SearchService;
use App\Services\Search\SqliteSearch;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SearchService::class, fn () => DB::getDriverName() === 'pgsql'
            ? new PostgresSearch
            : new SqliteSearch);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * `composer run dev`: the framework's worker only listens on "default",
     * but scraping, AI and media jobs run on their own queues.
     */
    protected function configureDevCommands(): void
    {
        // Quick jobs (scrapes, avatar downloads) first so long AI runs never starve them.
        DevCommands::artisan('queue:listen --queue=scrape,media,default,ai --tries=3 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Short, stable names for polymorphic columns (taggables, reports, audit logs...).
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'creator' => Creator::class,
            'channel' => Channel::class,
            'post' => Post::class,
            'project' => Project::class,
            'summary' => Summary::class,
            'claim' => Claim::class,
            'report' => Report::class,
        ]);
    }

    /**
     * Rate limits from plan §6.2, §9.2, §9.9 and §12.2.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('suggest', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('claim-check', function (Request $request) {
            $claim = $request->route('claim');
            $claimKey = $claim instanceof Claim ? $claim->id : (string) $claim;

            return [
                Limit::perMinutes(1, 3)->by('claim:'.$claimKey),
                Limit::perDay(30)->by('claim-user:'.$request->user()?->id),
            ];
        });

        RateLimiter::for('suggest-channel', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
