# Buildr

A directory of Telegram creators: search any channel, see who runs it, what they build, their journey, and all their socials. Profiles are written by AI from the channel's public posts, and every claim links to its source post. Creators claim their page by putting a short code in their channel bio, then edit it, pick a share card look and get updates from a Telegram bot.

Live at [buildr.mikaelen.site](https://buildr.mikaelen.site). The original spec is in [`telegram-creator-directory-build-plan.md`](telegram-creator-directory-build-plan.md); §18 lists where the build differs from it.

## Stack

Laravel 13 · Inertia v3 + React 19 + TypeScript (SSR) · Tailwind 4 + shadcn/ui · Fortify (admin 2FA) · Laravel AI SDK (Anthropic, Gemini or Groq) · Pest · PHPStan level 7.

|                          | Local    | Production (Laravel Cloud)                                   |
| ------------------------ | -------- | ------------------------------------------------------------ |
| Database                 | SQLite   | Postgres (`pg_trgm`, `unaccent`)                             |
| Queue / cache / sessions | database | database or Redis                                            |
| Files (avatars, cards)   | local    | S3-compatible bucket (`PUBLIC_DISK_DRIVER=s3`, e.g. R2)      |

## Run it locally

Requirements: PHP 8.4+ (with `gd`, `intl`, `pdo_sqlite`), Composer, Node 22.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # reserved slugs, curated tags and a few demo profiles
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

- **Creators** sign up at `/login` with an email and a password. Locally there's also a **Dev login** button (only when `APP_ENV=local`).
- **Admin:** `php artisan buildr:make-admin you@example.com`, then log in at `/admin/login` and set up 2FA (required).
- **Try the scraper:** `php artisan buildr:scrape @somechannel --pages=3`
- **Try the AI pipeline:** set an AI key (see below), then `php artisan buildr:process somechannel --sync` and `php artisan buildr:summary:show somechannel`.

### AI providers

Everything goes through the Laravel AI SDK; pick one in `.env` (all the options are in `.env.example`):

- **Anthropic** (default): `ANTHROPIC_API_KEY`.
- **Groq's free tier**: the Groq block, with `BUILDR_AI_BUDGET_PROFILE=compact` so calls are trimmed and paced to its 8K tokens a minute (an 80-post channel takes about 10 minutes and well under a cent).
- **Several free tiers in rotation**: `BUILDR_AI_LANES=gemini,groq` with both keys; when one runs out, the next takes over. Lane settings live in `config/buildr.php`.

`BUILDR_AI_DAILY_BUDGET_USD` caps spend; the admin panel's AI costs page shows usage and can pause AI.

### Telegram bot

There's no email: the bot is how Buildr reaches people. Creators connect it after logging in (a one-time `t.me/<bot>?start=…` link), and from then on it sends their password reset links (`/reset` in the bot, or "Forgot password?" on the site), updates about their page, and product announcements they can mute.

1. Create a bot with [@BotFather](https://t.me/BotFather) and set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` and a long random `TELEGRAM_WEBHOOK_SECRET`.
2. Register the webhook (needs a public HTTPS URL; locally, use a tunnel): `php artisan buildr:set-webhook`.
3. Optional: `TELEGRAM_ADMIN_CHAT_ID` for admin alerts, and `/setcommands` in BotFather (`reset`, `stop`, `start`, `help`).

Admins send announcements from **Admin → Announcements** (or `php artisan buildr:announce "…" --send`). For someone who never connected the bot and forgot their password, `php artisan buildr:reset-link their@email.com` prints a one-time link to hand over once you've confirmed it's them.

## Tests and checks

```bash
vendor/bin/pest                     # SQLite in memory
vendor/bin/phpstan analyse
vendor/bin/pint --test
npx tsc --noEmit
npm run check                       # lint + formatting for the frontend
```

CI (`.github/workflows/tests.yml`) runs these on SQLite, plus the test suite again on Postgres. Tests never call Telegram or an AI provider; fixtures in `tests/Fixtures/telegram` are made-up pages in t.me's markup.

## Deploying (Laravel Cloud)

1. Create an environment with Postgres, an S3-compatible bucket for the public disk, a queue worker (`scrape,ai,default,media`) and the scheduler. The build command is `npm run build:ssr`; the deploy command runs `php artisan migrate --force`.
2. Set `APP_ENV=production`, `APP_URL`, `DB_CONNECTION=pgsql`, the public disk (`PUBLIC_DISK_DRIVER=s3` plus bucket credentials) and the Buildr variables from `.env.example` (Telegram bot, AI keys, budget).
3. Run `php artisan inertia:start-ssr` as a daemon.
4. Register the bot webhook: `php artisan buildr:set-webhook`.
5. Create the admin with `php artisan buildr:make-admin`.

AI jobs can run for minutes: keep `DB_QUEUE_RETRY_AFTER` (or `REDIS_QUEUE_RETRY_AFTER`) above the 900s job timeout.

### Running your own copy

- The footer credits the maker; set `BUILDR_MAKER_HANDLE`, `BUILDR_MAKER_URL` and the other `BUILDR_MAKER_*` links in `config/buildr.php` to yours.
- `BUILDR_SUPPORT_TELEGRAM` is the public contact handle shown in the footer and the takedown policy.
- Read [SECURITY.md](SECURITY.md) before going live.

## Responsible use

Buildr only reads what Telegram publishes on its public `t.me/s/<channel>` web previews, with a few seconds between requests across all workers (`BUILDR_SCRAPE_*`). It never logs in to Telegram or reads private chats. Channel owners can claim their page, hide it, or have it removed and blocklisted (`/remove`). If you run your own copy, keep those defaults and honour removal requests.

## Where things live

| Area                              | Code                                                                                                    |
| --------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Scraper (t.me only, rate limited) | `app/Services/Telegram`, `app/Services/Scraping`, `app/Jobs/Scrape`                                     |
| AI pipeline                       | `app/Ai/Agents`, `app/Services/Ai`, `app/Jobs/Ai/ProcessChannel.php`, prompts in `resources/prompts/v1` |
| Directory + search                | `app/Services/Directory`, `app/Services/Search`                                                         |
| Claims, removal, disputes         | `app/Services/Claims`                                                                                   |
| Share cards / OG images           | `app/Services/Og`, `app/Http/Controllers/Public/ShareCardController.php`                                |
| Telegram bot + notifications      | `app/Services/Telegram`, `app/Services/Notifications`, `app/Http/Controllers/TelegramWebhookController.php` |
| Public pages                      | `app/Http/Controllers/Public`, `resources/js/pages/public`                                              |
| Creator onboarding + dashboard    | `app/Http/Controllers/Creator`, `resources/js/pages/onboarding`, `resources/js/pages/dashboard`         |
| Admin panel                       | `app/Http/Controllers/Admin`, `resources/js/pages/admin`                                                |

## License

MIT, see [LICENSE](LICENSE). The bundled Noto fonts are under the SIL Open Font License ([resources/fonts/OFL.txt](resources/fonts/OFL.txt)).
