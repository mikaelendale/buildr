# Buildr

A directory of Telegram creators: search any channel, see who runs it, what they build, their journey, and all their socials. Profiles are written by AI from the channel's public posts, and every claim links to its source post.

The full spec lives in [`telegram-creator-directory-build-plan.md`](telegram-creator-directory-build-plan.md); §18 lists where the build differs from it.

## Stack

Laravel 13 · Inertia v3 + React 19 + TypeScript (SSR) · Tailwind 4 + shadcn/ui · Fortify (admin 2FA) · Laravel AI SDK (Claude, or Groq on a budget) · Pest 5 · PHPStan level 7.

|                          | Local    | Production (Laravel Cloud)       |
| ------------------------ | -------- | -------------------------------- |
| Database                 | SQLite   | Postgres (`pg_trgm`, `unaccent`) |
| Queue / cache / sessions | database | Redis (+ Horizon)                |

## Run it locally

Requirements: PHP 8.4+ (with `gd`, `intl`, `pdo_sqlite`), Composer, Node 22.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # seeds reserved slugs, curated tags and local demo profiles
php artisan storage:link
npm run build:ssr
```

Then either `composer run dev` (server, a queue worker on every Buildr queue, the scheduler and Vite in one terminal), or in separate terminals:

```bash
php artisan serve                   # http://localhost:8000
php artisan inertia:start-ssr       # server-side rendering
php artisan queue:work --queue=scrape,ai,default,media   # required: scraping + AI run here
php artisan schedule:work           # optional: claims, canary, daily scrapes
npm run dev                         # optional: hot reload while editing the frontend
```

Without a worker on the `scrape`, `ai` and `media` queues, onboarding waits at "Scraping your public posts" (the page says the channel is in line).

- **Creator login:** the Telegram widget only works on a domain registered with BotFather. Locally, use the **"Local dev login"** button on `/login`.
- **Admin:** `php artisan buildr:make-admin you@example.com`, then log in at `/admin/login` and set up 2FA (required).
- **Try the scraper:** `php artisan buildr:scrape @somechannel --pages=3`
- **Try the AI pipeline:** set `ANTHROPIC_API_KEY` (or the Groq block in `.env.example`), then `php artisan buildr:process somechannel --sync` and `php artisan buildr:summary:show somechannel`. On Groq's free tier, use `BUILDR_AI_BUDGET_PROFILE=compact`: calls are trimmed and paced to its 8K tokens/minute, so an 80-post channel takes about 10 minutes and well under one cent.

## Tests and checks

```bash
vendor/bin/pest                     # 140 tests (SQLite in memory)
vendor/bin/phpstan analyse
vendor/bin/pint --test
npx tsc --noEmit
```

CI (`.github/workflows/tests.yml`) runs all of these on SQLite, plus the test suite again on Postgres.

## Deploying (Laravel Cloud)

1. Create staging + production environments with Postgres, Redis, an object storage bucket, a queue worker (`scrape,ai,default,media`) and the scheduler.
2. Set `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, and the Buildr variables in `.env.example` (Telegram bot, `ANTHROPIC_API_KEY`, budget).
3. Build with `npm run build:ssr` and run `php artisan inertia:start-ssr` as a daemon.
4. In BotFather: `/setdomain` to your domain, then `php artisan buildr:set-webhook`.
5. Create the admin with `php artisan buildr:make-admin`.
6. Install Horizon on Redis environments (on Windows dev machines use `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`).

## Where things live

| Area                              | Code                                                                                                    |
| --------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Scraper (t.me only, rate limited) | `app/Services/Telegram`, `app/Services/Scraping`, `app/Jobs/Scrape`                                     |
| AI pipeline                       | `app/Ai/Agents`, `app/Services/Ai`, `app/Jobs/Ai/ProcessChannel.php`, prompts in `resources/prompts/v1` |
| Directory + search                | `app/Services/Directory`, `app/Services/Search`                                                         |
| Claims, removal, disputes         | `app/Services/Claims`                                                                                   |
| Public pages                      | `app/Http/Controllers/Public`, `resources/js/pages/public`                                              |
| Creator onboarding + dashboard    | `app/Http/Controllers/Creator`, `resources/js/pages/onboarding`, `resources/js/pages/dashboard`         |
| Admin panel                       | `app/Http/Controllers/Admin`, `resources/js/pages/admin`                                                |
