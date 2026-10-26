# Telegram Creator Directory: End-to-End Build Plan

> Working name: **Buildr** (rename anytime; the name only appears in config, copy, and the claim-code prefix).
> One place to search any Telegram creator, see who actually runs a channel, what they build, their journey, and all their socials, with fun, accurate AI profiles generated from their public posts.

---

## 0. Locked Decisions

| Decision        | Choice                                                                                                                     |
| --------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Signup          | Open to everyone; every profile goes through **admin approval** before going public                                        |
| Creator login   | **Telegram Login Widget** only (no passwords, no email)                                                                    |
| Admin login     | Separate `/admin/login` with email + password + **mandatory TOTP 2FA**                                                     |
| Data source     | **Scraping public web previews** (`t.me/{channel}` and `t.me/s/{channel}`). No bot-as-admin, no MTProto, no account access |
| Ownership proof | Creator puts a **claim code** in the channel description (or a post); the scraper sees it and verifies                     |
| Big channels    | Admin creates **unclaimed channel pages** from public info; the owner can claim later                                      |
| Summaries       | Creative, fun, warm, **grounded in real posts with citations**; person-level detail only after a claim                     |
| Takedown        | Anyone can report; owners can remove their channel with a claim code; removed channels are blocklisted from scraping       |

---

## 1. Stack & Infrastructure

| Layer          | Choice                                                              | Notes                                                                                                                           |
| -------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Backend        | Laravel 13                                                          | Queues, scheduler, Fortify (admin 2FA), policies                                                                                |
| Frontend       | Inertia v3 + React + TypeScript                                     | **Inertia SSR enabled**, so public pages are crawlable and have real meta tags                                                  |
| UI             | Tailwind 4 + shadcn/ui                                              | Mobile-first: most traffic comes from Telegram's in-app browser                                                                 |
| DB             | **SQLite locally**, PostgreSQL on staging + production              | Postgres extensions: `pg_trgm`, `unaccent`; `pgvector` later (v1.1 topic clustering). See §1.1 for keeping both drivers working |
| Queue          | **Database queue locally**, Redis + Horizon on staging + production | Separate queues: `scrape`, `ai`, `default`, `media`                                                                             |
| Cache / locks  | Database cache locally, Redis on staging + production               | The scraper's global rate limiter uses cache locks, so it works on both                                                         |
| Hosting        | Laravel Cloud                                                       | Web, queue workers, scheduler, managed Postgres, object storage                                                                 |
| Object storage | Laravel Cloud bucket (S3-compatible)                                | Avatars, OG images, raw HTML snapshots                                                                                          |
| HTML parsing   | Guzzle + Symfony DomCrawler                                         |                                                                                                                                 |
| Images         | Intervention Image                                                  | Avatar resizing + OG image generation                                                                                           |
| LLM            | Claude API via a thin service class (or Prism)                      | Haiku-class model for per-post extraction; Sonnet/Opus-class for synthesis + verification                                       |
| Telegram       | One bot (via BotFather)                                             | Used for Login Widget + DM notifications. **Not** used to read channels                                                         |
| Monitoring     | Laravel Nightwatch or Sentry + log channel                          | Plus a scraper "canary" job (see §7.8)                                                                                          |
| Analytics      | First-party `profile_views` table + Plausible (optional)            |                                                                                                                                 |

**Environments:** `local` (Windows, SQLite, database queue + cache), `staging` (Laravel Cloud, Postgres + Redis, separate bot), `production` (Laravel Cloud, Postgres + Redis).

**Config values (`config/buildr.php`):**

- `claim_code_prefix` (`BUILDR`), `claim_code_ttl_hours` (48)
- `scrape.min_delay_ms` (2000), `scrape.jitter_ms` (1500), `scrape.backfill_max_posts` (3000), `scrape.backfill_max_months` (36)
- `ai.extraction_model`, `ai.synthesis_model`, `ai.verify_model`, `ai.prompt_version`, `ai.daily_budget_usd`
- `content.min_text_posts_for_profile` (20)
- `regen.creator_daily_limit` (3)
- `default_timezone` (`Africa/Addis_Ababa`)

### 1.1 Local (SQLite) vs production (Postgres)

Local dev runs on SQLite with the database queue; staging and production run on Postgres + Redis. Rules that keep both working:

- **Postgres-only schema is guarded by driver.** Things like the `search_documents.tsv` generated column, `gin_trgm_ops` indexes, and `CREATE EXTENSION` statements go inside `if (DB::getDriverName() === 'pgsql')` blocks in their migrations. On SQLite those columns and indexes simply don't exist.
- **Search has two implementations behind one `SearchService` interface:** `PostgresSearch` (trigram + full-text ranking from §9.2) and `SqliteSearch` (`LIKE` on normalized `search_documents.value`, same ranking tiers minus similarity scores). The service is chosen by DB driver. Local search is "good enough to click around"; ranking quality is only judged on staging.
- **Stay portable everywhere else:** use JSON columns through Eloquent casts (no `jsonb` operators in queries), no `ILIKE` (normalize to lowercase on write instead), no Postgres-only date functions in queries (do date math in PHP; stats in §8.4 are computed in code anyway).
- **CI runs the suite twice:** on SQLite (fast) and on a Postgres service container (catches driver-specific bugs, runs the search ranking tests, which are marked `pgsql`-only).
- **Horizon on Windows:** Horizon needs `ext-pcntl`/`ext-posix`, which don't exist on Windows. Install it with `composer require laravel/horizon --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`, and only register its routes and service provider when `QUEUE_CONNECTION=redis`. Locally use `php artisan queue:work` and `queue:failed` instead.

---

## 2. Roles & Access

| Role        | How they get in                          | Can do                                                                                                                             |
| ----------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Visitor** | No login                                 | Landing, search, discover, tags, profile pages, report, share                                                                      |
| **Creator** | Telegram Login Widget                    | Claim channels, onboarding, edit their profile, review/regenerate AI summary, view their stats, delete account, remove channel     |
| **Admin**   | `/admin/login` (email + password + TOTP) | Everything in §10: review queue, add unclaimed channels, moderate, feature, ban, resolve reports/disputes, monitor jobs + AI costs |

An admin account is created only via an artisan command: `php artisan buildr:make-admin {email}`. There is no admin signup route.

---

## 3. Data Model

All tables have `id`, `created_at`, `updated_at` unless noted.

### users

`telegram_id` (bigint, unique, nullable for admin-only accounts), `telegram_username`, `first_name`, `last_name`, `avatar_path`, `role` (`creator|admin`), `email` (nullable, admins), `password` (nullable, admins), Fortify 2FA columns, `bot_can_message` (bool), `timezone`, `banned_at`, `ban_reason`, `last_login_at`

### creators _(the person profile)_

`user_id` (nullable, unique; null = never claimed), `slug` (unique), `display_name`, `real_name` (nullable, creator-entered only), `real_name_am` (nullable, Amharic script), `bio` (creator-written, max 600 chars), `location` (nullable, free text), `avatar_path`, `primary_channel_id` (nullable; its summary supplies the profile header's hook + vibe tags, see §9.4), `status` (`draft|pending_review|published|changes_requested|rejected|hidden`), `rejection_reason`, `onboarding_step` (tinyint 1–6, the last step reached; drives resume), `onboarding_completed_at` (nullable), `published_at`, `featured_at` (nullable), `slug_changed_at`, `timezone`

### creator_socials

`creator_id`, `platform` (`x|github|linkedin|instagram|tiktok|youtube|website|substack|other`), `url`, `handle`, `sort`

### channels

`username` (unique, lowercase), `title`, `description`, `avatar_path`, `subscribers`, `creator_id` (nullable), `claim_status` (`unclaimed|claimed`), `visibility` (`hidden|published|removed`), `added_by` (`self|admin`), `availability` (`ok|no_preview|not_found|private`), `scrape_status` (`idle|queued|backfilling|incremental|failed`), `newest_msg_id`, `oldest_msg_id`, `backfill_complete` (bool), `last_scraped_at`, `last_processed_at`, `timezone`, `failure_count`, `last_error`

### channel_snapshots

`channel_id`, `subscribers`, `captured_at` (used for growth stats and "trending")

### posts

`channel_id`, `msg_id` (unique together), `posted_at` (UTC), `edited` (bool), `text`, `text_html` (sanitized), `lang` (`en|am|mixed|other`), `views` (int, parsed from "12.3K"), `has_media`, `media_types` (json), `is_forward`, `forwarded_from`, `reply_to_msg_id`, `hashtags` (json), `is_service`, `is_ad` (bool), `is_deleted` (bool), `content_hash`, `scraped_at`, `views_updated_at`

### post_links

`post_id`, `url`, `domain`, `kind` (`github|x|telegram|website|product|youtube|other`)

### post_extractions

`post_id` (unique), `type` (`project_launch|progress_update|opinion|tutorial|resource_share|life|promo|announcement|other`), `topics` (json), `projects` (json), `tools` (json), `milestone` (nullable), `tone` (`hype|reflective|educational|casual|funny|serious`), `is_personal_sensitive` (bool), `model`, `prompt_version`

### projects

`channel_id`, `creator_id` (nullable), `name`, `slug`, `url` (nullable), `description`, `status` (`idea|building|launched|abandoned|unknown`), `first_seen_post_id`, `last_seen_post_id`, `mention_count`, `source` (`ai|creator`), `hidden` (bool), `sort`

### channel_stats

`channel_id` (unique), `stats` (json, see §8.4), `computed_at`

### summaries

`channel_id`, `creator_id` (nullable), `version`, `mode` (`unclaimed|claimed`), `content` (json, see §8.6), `citations` (json: claim → [msg_ids]), `verification` (json report), `status` (`generating|draft|verified|failed_verification|approved|superseded`), `model`, `prompt_version`, `input_tokens`, `output_tokens`, `triggered_by` (`system|creator|admin`)

### summary_overrides

`channel_id`, `section_key`, `value` (json, null when only hiding), `hidden` (bool), `source` (`creator|admin`), `edited_by` (user id), unique (`channel_id`, `section_key`). Keyed on the **channel** (summaries are per channel), so it works for unclaimed pages (admin edits from §10.2/§10.6) and for creators with several channels. Edits are stored here so regeneration never wipes them. On claim, admin overrides carry over and the creator can replace them. On account deletion, `source = creator` overrides are deleted (admin overrides are kept if the channel goes back to unclaimed).

### tags / taggables

`tags`: `slug`, `name`, `is_curated`. `taggables`: `tag_id`, `taggable_type`, `taggable_id`, `source` (`ai|creator|admin`)

### claims

`user_id`, `channel_username`, `channel_id` (nullable), `purpose` (`claim|removal`), `code` (unique), `status` (`pending|verified|expired|failed|disputed`), `attempts`, `last_checked_at`, `expires_at`, `verified_at`

### reports

`reporter_user_id` (nullable), `reporter_contact` (nullable), `target_type`, `target_id`, `reason` (`impersonation|wrong_info|privacy|offensive|spam|remove_me|other`), `details`, `status` (`open|resolved|dismissed`), `resolved_by`, `resolution_note`

### scrape_blocklist

`username` (unique), `reason`, `created_by`

### reserved_slugs

seeded list (§12.3)

### profile_views

`creator_id` or `channel_id`, `date`, `views`, `unique_views` (aggregated daily; no raw IPs stored)

**Unique-view dedupe:** on each view, compute `sha256(ip | user_agent | daily_salt)`, where `daily_salt` is a random value generated once per day and kept in cache only (so yesterday's hashes can't be linked to today's or reversed). Then `Cache::add("pv:{date}:{target}:{hash}", 1, until end of day)`: if `add` returns true it's a unique view. Every view increments a cache counter. The daily aggregate job (§7.5) flushes counters into `profile_views`. Works on the database cache locally and Redis in production.

### ai_runs

`job`, `subject_type`, `subject_id`, `model`, `input_tokens`, `output_tokens`, `cost_usd_est`, `status`, `error`, `duration_ms`

### audit_logs

`actor_id`, `action`, `subject_type`, `subject_id`, `meta` (json)

### search_documents

`searchable_type`, `searchable_id`, `field` (`display_name|real_name|real_name_am|channel_username|channel_title|tag|project|summary`), `value` (normalized: lowercased, `@`/`t.me/` stripped), `weight`. **Postgres only** (guarded per §1.1): a generated `tsv` `tsvector` column using the `simple` config (works for Amharic tokens) with a GIN index, and a `gin_trgm_ops` index on `value`. On SQLite these don't exist and `SqliteSearch` uses `LIKE` on `value`.

---

## 4. Route Map

### Public

| Route                                              | Page                                                                                                                                                                                                                                                                                                                              |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/`                                                | Landing                                                                                                                                                                                                                                                                                                                           |
| `/search?q=`                                       | Search results                                                                                                                                                                                                                                                                                                                    |
| `/discover`                                        | Browse grid with filters                                                                                                                                                                                                                                                                                                          |
| `/tags/{slug}`                                     | Tag page                                                                                                                                                                                                                                                                                                                          |
| `/{slug}`                                          | Creator profile (claimed). **Registered last** in `routes/web.php`, after every other route file is loaded, with `->where('slug', '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]')` so it never matches `sitemap.xml`, `robots.txt`, or anything with a dot or slash. Reserved slugs (§12.3) stop creators from taking names of existing routes |
| `/c/{username}`                                    | Channel page (unclaimed). If claimed, 301 → `/{creator-slug}#channel-{username}`                                                                                                                                                                                                                                                  |
| `/claim`                                           | Explainer + start claim (redirects to login if needed)                                                                                                                                                                                                                                                                            |
| `/remove`                                          | Remove-my-channel flow                                                                                                                                                                                                                                                                                                            |
| `/about`, `/terms`, `/privacy`, `/takedown-policy` | Static pages                                                                                                                                                                                                                                                                                                                      |
| `/sitemap.xml`, `/robots.txt`                      | SEO                                                                                                                                                                                                                                                                                                                               |
| `/og/{type}/{id}.png`                              | OG image (served from storage)                                                                                                                                                                                                                                                                                                    |

### Auth

| Route                          | Purpose                                |
| ------------------------------ | -------------------------------------- |
| `/login`                       | Telegram Login Widget page             |
| `POST /auth/telegram/callback` | Verify widget payload, log in          |
| `POST /logout`                 |                                        |
| `/admin/login`                 | Admin email + password → 2FA challenge |

### Creator (auth: creator)

| Route                 | Page                                          |
| --------------------- | --------------------------------------------- |
| `/onboarding/*`       | 6-step onboarding (§6.3)                      |
| `/dashboard`          | Status, stats, quick actions                  |
| `/dashboard/profile`  | Edit basics, socials, bio, tags               |
| `/dashboard/summary`  | Review / edit / hide / regenerate AI sections |
| `/dashboard/projects` | Edit / hide / reorder / add projects          |
| `/dashboard/channels` | Add + verify more channels, remove a channel  |
| `/dashboard/settings` | Slug, timezone, notifications, delete account |

### Admin (auth: admin + 2FA confirmed)

`/admin`, `/admin/review`, `/admin/review/{creator}`, `/admin/channels`, `/admin/channels/new`, `/admin/channels/{id}`, `/admin/creators`, `/admin/creators/{id}`, `/admin/claims`, `/admin/reports`, `/admin/jobs`, `/admin/ai-costs`, `/admin/featured`, `/admin/tags`, `/admin/blocklist`, `/admin/audit`

### API (JSON, rate limited)

`GET /api/search/suggest?q=` (autocomplete), `POST /api/claims/{id}/check`, `GET /api/onboarding/progress`, `POST /api/reports`, `POST /telegram/webhook` (bot updates, secret-token header)

---

## 5. Authentication In Detail

### 5.1 Creator login (Telegram Login Widget)

1. Create the bot in BotFather → `/setdomain` → the production domain (staging uses a separate bot + domain).
2. The `/login` page embeds the widget with `data-request-access="write"`, so the bot can DM the user later.
3. The widget posts `id, first_name, last_name, username, photo_url, auth_date, hash` to `/auth/telegram/callback`.
4. Server verification:
    - Build `data_check_string`: every field except `hash`, sorted alphabetically, joined `key=value` with `\n`.
    - `secret_key = SHA256(bot_token)`; expected = `HMAC_SHA256(data_check_string, secret_key)` as hex.
    - Use `hash_equals` to compare; reject if `auth_date` is older than 24h (replay protection).
5. Upsert the user by `telegram_id`; copy `photo_url` into our storage (Telegram URLs can change); set `last_login_at`.
6. If banned → show the banned page. If there's no creator profile yet → redirect to `/onboarding`. Otherwise → `/dashboard`.
7. Session: standard Laravel session, 30-day remember cookie, regenerate on login.

### 5.2 Admin login

1. `/admin/login`: email + password (Fortify), throttled to 5 attempts/min per IP + email.
2. 2FA is mandatory: if not set up, force the TOTP setup screen before any admin page. Recovery codes are shown once.
3. Middleware stack on `/admin/*`: `auth`, `role:admin`, `2fa.confirmed`.
4. Every admin mutation writes to `audit_logs`.
5. Admin session idle timeout: 2h.

### 5.3 Bot webhook

- `setWebhook` with a `secret_token`; the endpoint rejects requests without a matching header.
- Handles `/start` (sets `bot_can_message = true`, replies with a link to the dashboard), `/stop` (sets it false), and `my_chat_member` blocked events (sets it false).
- Outgoing DMs go through a queued `TelegramNotifier` that skips users with `bot_can_message = false`.

---

## 6. Creator Journeys

### 6.1 Claim code

- Format: `BUILDR-XXXXXX` (6 chars, Crockford base32, no ambiguous chars).
- One active code per (user, channel, purpose). Expires after 48h.

### 6.2 Verifying a claim

1. Creator enters `@handle`, `t.me/handle`, or a full URL; normalize it to a lowercase username.
2. Pre-checks: not blocklisted, not already claimed by someone else (if claimed → offer to open a **dispute**), and `t.me/{username}` returns a channel (not a user/bot/group). The page type is detectable from the markup.
3. Show the code with copy button + instructions: _"Paste this anywhere in your channel description (or post it in the channel). You can delete it after we verify."_
4. "Check now" button → `POST /api/claims/{id}/check` (throttle: 1 per 20s per claim, 30 per day per user):
    - Fetch `t.me/{username}` and check the description.
    - Fetch the first page of `t.me/s/{username}` and check the last ~20 posts.
    - Found → `verified`, link `channel.creator_id`, set `claim_status = claimed`, write an audit log, DM the user.
    - Not found → increment attempts and show "Not found yet. Telegram can take a minute to update."
5. A background job also auto-checks pending claims every 5 min until expiry, so the creator can close the tab.
6. If the channel was an existing **unclaimed page**: it is attached to the creator, and the summary is regenerated in `claimed` mode after onboarding.

### 6.3 Onboarding (6 steps, progress bar, resumable)

State lives in `creators.onboarding_step`: a `draft` creator row is created when the user enters step 2, each completed step bumps the value, and `/onboarding` redirects to the stored step. `onboarding_completed_at` is set on submit. Users without a creator row start at step 1.

1. **Welcome**: what the site is, what we read (public posts only), what they control.
2. **Add channel**: enter handle → pre-checks (§6.2).
3. **Verify**: code + check (§6.2). Skipping is allowed only for the "I'll do it later" draft state. **Nothing is published without a verified channel.**
4. **Basics** (filled while scraping runs): display name (prefilled from Telegram), real name (optional, with a note that it will be public), Amharic name (optional), slug (prefilled, live availability check), short bio, location (optional), timezone, socials (add rows; auto-detect platform from URL), up to 5 tags.
5. **Building your profile**: live progress via polling `/api/onboarding/progress` (scraping posts → reading posts → finding projects → writing your profile). Copy: _"This can take a few minutes. We'll message you on Telegram when it's ready."_ The creator can leave.
6. **Review**: rendered profile preview with per-section controls: edit, hide, regenerate (limit 3/day). Projects can be edited, hidden, or added. Then **Submit for review** → `status = pending_review` → admin gets notified.

Edge: the channel has fewer than `min_text_posts_for_profile` text posts → skip AI sections and publish a "basic" profile (creator-written content + channel info + stats only).

### 6.4 After submission

- Approved → `published`, DM "You're live: {url}" with a share button.
- Changes requested → DM with the admin's note; the dashboard shows a banner with what to fix.
- Rejected → DM with the reason; they can contact or appeal via a report.
- Edits **after** publishing:
    - Bio, socials, tags, project edits, section hides → **live immediately**, logged to the admin "recent edits" feed for spot checks.
    - Display name / real name / slug changes → back to `pending_review` for that field only (the old value stays live until approved).
    - AI regeneration → auto-publish if verification passes; otherwise → review queue.

### 6.5 Remove my channel (no profile wanted)

`/remove` → Telegram login → enter channel → removal claim code → verify (same check) → channel `visibility = removed`, posts + summaries deleted, username added to `scrape_blocklist`, confirmation DM. Also reachable from the dashboard.

### 6.6 Delete account

Settings → type the slug to confirm → creator profile + overrides + socials deleted, the user is anonymized, and their claimed channels go back to **removed + blocklisted** (default) or **unclaimed** (if the creator opts in with a checkbox).

---

## 7. Scraper Pipeline

### 7.1 Sources

- `https://t.me/{username}`: title, description, avatar, subscriber count, page type (channel/user/group/bot).
- `https://t.me/s/{username}`: post feed, ~20 posts per page; older pages via `?before={msg_id}`.
- If `/s/` redirects to `/{username}`, the channel has no web preview → `availability = no_preview`.

### 7.2 Parsing (per `.tgme_widget_message`)

- `data-post` → `username/msg_id`
- `.tgme_widget_message_text` → text (keep sanitized HTML too, for links/formatting)
- `time[datetime]` → `posted_at`
- `.tgme_widget_message_views` → views (parse `K`/`M`)
- `.tgme_widget_message_forwarded_from` → forward info
- `.tgme_widget_message_reply` → reply target
- Media blocks (photo/video/document/poll/voice/sticker) → `media_types`
- Edited marker → `edited`
- Service messages (pinned, title changed, etc.) → `is_service`
- Reactions block if present → stored, best-effort
- Albums: grouped media renders as one widget; store the first msg_id, merge text
- Extract all `<a href>` → `post_links` with a `kind` classifier; extract hashtags

**Keep the parser in one class with HTML fixture tests.** When Telegram changes markup, you fix one file.

### 7.3 Politeness & safety

- A global rate limiter (Redis/cache lock) across all workers: ≥2s between requests + random jitter.
- Honest User-Agent string with a contact URL.
- 429/5xx → exponential backoff (1m, 5m, 30m, 2h); after 5 consecutive failures → `scrape_status = failed` and an admin alert.
- Never scrape blocklisted usernames (checked inside the job, not just at dispatch).
- Store raw HTML of each fetched page in object storage for 30 days, so the parser can be re-run without re-fetching.

### 7.4 Jobs

| Job                   | Queue  | What it does                                                                                                                                                                 |
| --------------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `FetchChannelInfo`    | scrape | `t.me/{u}` → title, desc, avatar (downloaded + resized to 400/128/64), subscribers, snapshot row                                                                             |
| `BackfillChannelPage` | scrape | Fetch one page (`before=oldest_msg_id`), upsert posts, re-dispatch itself until the limits hit or there are no older posts → `backfill_complete` → dispatch `ProcessChannel` |
| `IncrementalScrape`   | scrape | Fetch newest pages until reaching `newest_msg_id`; upsert new posts                                                                                                          |
| `RefreshRecentViews`  | scrape | Weekly: re-fetch pages covering the last 30 days, update views, mark posts that vanished as `is_deleted`                                                                     |
| `ProcessChannel`      | ai     | Chain: `ExtractPosts` (batched) → `ComputeStats` → `BuildProjects` → `GenerateSummary` → `VerifySummary` → `BuildSearchIndex` → `RenderOgImage`                              |

### 7.5 Schedules

- Every 5 min: auto-check pending claims.
- Daily: `IncrementalScrape` for all published + pending channels (staggered).
- Daily: subscriber snapshot.
- Weekly: `RefreshRecentViews`; regenerate the summary if ≥15 new text posts or a new milestone was detected.
- Daily: expire claims, aggregate `profile_views`, prune raw HTML older than 30 days.
- Hourly: parser canary (§7.8).

### 7.6 Renames & disappearances

- `t.me/{u}` 404 / no longer a channel → `availability = not_found`; the public page shows "This channel is unavailable"; it's hidden from discover after 7 days.
- Rename: the creator updates the handle in the dashboard → re-verify with a code → we check that msg_ids + content hashes of 5 known posts match on the new handle → the username is migrated, keeping all data.

### 7.7 Content filters before AI

- Drop: service messages, posts with no text and only stickers/emoji, exact duplicates.
- Tag but keep: forwards (`is_forward`), promos/ads (simple keyword + link heuristics, refined by extraction).
- Language detection per post (Ethiopic Unicode range ratio → `am`/`mixed`/`en`).

### 7.8 Canary

An hourly job scrapes a known stable public channel. If it parses 0 posts or fields go missing → alert the admin via Telegram DM + mark scraping paused. This catches markup changes before they corrupt data.

---

## 8. AI Pipeline

### 8.1 Principles

- **Creative voice, factual content.** Every factual statement must map to cited post IDs.
- **Two modes:**
    - `unclaimed`: talk about the **channel** (what it posts, projects mentioned, style, cadence). No guesses about who runs it, no person-level traits, no real names unless the channel itself states them in its description.
    - `claimed`: can talk about the creator as a person **using what they publicly post + what they wrote in onboarding**.
- **Never include** (both modes): health, family/relationships, religion, politics, finances/salary, location beyond city level, appearance, anything marked `is_personal_sensitive`. Warm, never roasty.

### 8.2 Step 1: per-post extraction (cheap model, batched 30–50 posts/call)

Input: msg_id, date, lang, text (truncated to ~1,500 chars), is_forward, links.
Output: a strict JSON array validated server-side (invalid → retry once → mark the post `type=other`).

```json
[
    {
        "msg_id": 1234,
        "type": "project_launch",
        "topics": ["AI agents", "Laravel"],
        "projects": [{ "name": "Chapa Bot", "role": "own|mentioned" }],
        "tools": ["Laravel", "OpenAI API"],
        "milestone": "launched v1",
        "tone": "hype",
        "is_personal_sensitive": false
    }
]
```

### 8.3 Step 2: project building (code + one small LLM call)

- Group `projects` mentions with `role=own` by normalized name (lowercase, strip punctuation, fuzzy-merge with trigram > 0.8).
- Attach URLs from links in the same posts (GitHub repos, product domains).
- Status from milestones: launch milestone → `launched`; recent mentions only → `building`; silent for 6+ months after building → `unknown`.
- One LLM call writes a 1-sentence description per project from its posts.

### 8.4 Step 3: stats (pure code, the channel's timezone)

- `active_since`, `total_posts`, `text_posts`, `posts_per_week` (last 90d)
- `longest_streak_weeks` (consecutive weeks with ≥1 post), `current_streak_weeks`
- `peak_hour`, `night_owl_pct` (00:00–05:00), `early_bird_pct` (05:00–08:00), with a label: Night Owl / Early Bird / Daytime / All Hours
- `avg_post_length`, `language_mix` (% en / am / mixed)
- `top_post` (views ÷ subscribers-at-time, from the nearest snapshot), `top_5_posts`
- `top_emoji`, `favorite_word` (stopwords removed for English + an Amharic stopword list)
- `ship_rate` (milestones per month), `projects_count`, `forward_ratio`
- `subscriber_growth_30d` (from snapshots; starts empty, fills over time)

### 8.5 Step 4: synthesis (strong model, map → reduce)

- **Map:** for each month with posts, send that month's extractions + its top 5 posts by views → a ≤120-word month summary with cited msg_ids.
- **Reduce:** send month summaries + stats + projects + top posts + (claimed mode) the creator's onboarding fields → the final JSON (§8.6).
- **Prompt includes:** the mode rules, the banned topics list, a tone guide, **3 hand-written example profiles** (write these yourself; they define the voice), and the output schema.
- **Big channels:** if month summaries exceed the token budget, first merge them into quarters.

### 8.6 Summary JSON schema

```json
{
    "hook": "Ships at 3am, explains it at 9am, hasn't missed a week since March.",
    "vibe_tags": ["Builder", "Teacher", "Chaos"],
    "intro": "2–3 sentence intro paragraph",
    "building_now": [
        {
            "project_slug": "chapa-bot",
            "line": "why it's interesting",
            "cites": [1234]
        }
    ],
    "eras": [
        {
            "name": "The Laravel Era",
            "from": "2024-01",
            "to": "2024-09",
            "story": "2–3 sentences",
            "cites": [101, 140]
        }
    ],
    "start_here": [{ "msg_id": 2001, "why": "one-line reason" }],
    "in_their_words": [{ "msg_id": 1890, "quote": "≤25 words, verbatim" }],
    "how_they_work": { "text": "stack, habits, cadence", "cites": [55] },
    "fun_facts": [
        {
            "text": "Hasn't missed a single week in 31 weeks straight.",
            "source": "stats.current_streak_weeks"
        }
    ]
}
```

- Every example in prompts and in this schema must itself follow §8.1. The model copies examples, so an example that mentions religion, health, or family teaches it to break the rules.
- `how_they_work` is generated only in claimed mode, and `in_their_words` is capped at 2 quotes.
- Stat cards are **rendered from `channel_stats` directly**, not by the LLM. `fun_facts` may only reference stats keys.

### 8.7 Step 5: verification

1. **Deterministic checks:** every cited msg_id exists, belongs to this channel, and isn't deleted; every `in_their_words.quote` appears verbatim in that post; every `project_slug` exists; `fun_facts.source` keys exist and the numbers match.
2. **LLM check** (a different call): for each claim + its cited post texts → `supported | unsupported | sensitive`.
3. Unsupported or sensitive items are **dropped** (not rewritten). If a required section (hook, intro) fails → regenerate once → if it still fails → `failed_verification` → admin queue.
4. Store the full report in `summaries.verification` for the admin view.

### 8.8 Rendering + overrides

Final page content = AI summary, with `summary_overrides` applied per section (edited value or hidden). Regeneration creates a new summary version; overrides persist; the creator sees "AI updated sections you edited. Keep your version / use new?" per section.

### 8.9 Cost control

- Extraction runs only on new posts (idempotent by `post_id` + `prompt_version`).
- Backfill caps (`backfill_max_posts`, `backfill_max_months`).
- `ai_runs` logs every call. Admin dashboard shows the total, plus per-channel cost and a daily budget cap (`ai.daily_budget_usd`); when it's hit, AI jobs pause and the admin gets alerted.
- Rough order of magnitude: a 3,000-post channel costs well under a few dollars end-to-end. Measure the real number on the first 5 channels and set the budget from that.

---

## 9. Public Pages (what each looks like)

### 9.1 Landing (`/`)

1. **Nav:** logo · Discover · Claim your page · Login (→ Dashboard when logged in).
2. **Hero:** headline (_"Find out who's behind the channel."_), subline, **big search bar** with instant autocomplete (avatar + name + @channel + subscriber count; keyboard navigable). Placeholder: _"Search a name, channel, or @handle"_. It accepts pasted `t.me/...` links.
3. **Featured builders:** admin-picked carousel of profile cards (avatar, name, @channel, hook line, vibe tags).
4. **Trending this week:** ranked by profile views (7d) + subscriber growth.
5. **Browse by tag:** curated tag chips (AI, Design, Web Dev, Startups, Students, Crypto, Content…).
6. **Recently joined:** newest published claimed profiles.
7. **How it works:** 3 steps: creators claim their page → we read their public posts → you get the full picture.
8. **Creator CTA:** _"Run a channel? Your page might already exist. Claim it in 2 minutes."_ → `/claim`.
9. **Footer:** About, Terms, Privacy, Takedown policy, Remove my channel, contact (Telegram).

### 9.2 Search (`/search?q=`)

- The query is normalized (strip `@`, `t.me/`, `https://`, whitespace).
- **Ranking order:**
    1. Exact channel username match
    2. Exact display/real name match (incl. Amharic)
    3. Trigram similarity on names/usernames/titles (≥0.3)
    4. Full-text on tags, projects, summary text
- Boosts: claimed + verified > unclaimed; log(subscribers); published only.
- Result card: avatar, display name (or channel title if unclaimed), real name if provided, @channel chips, hook, tags, "Unclaimed" badge where relevant.
- **Empty state:** _"No one here yet."_ plus an input for "Suggest a channel" → creates an admin task (not an auto-scrape) and "Is it yours? Claim it".
- Throttle: 60 req/min per IP for suggest, 30/min for full search.

### 9.3 Discover (`/discover`)

- Grid of profile cards, infinite scroll (cursor pagination).
- Filters: tags (multi), claimed only, language (en/am), active recently (posted in the last 30d), size buckets (<1K, 1–10K, 10–50K, 50K+).
- Sort: Trending · Newest · Most subscribers · Most active.
- Filter state lives in the URL (shareable).

### 9.4 Creator profile (`/{slug}`) (claimed)

Top to bottom, mobile-first:

1. **Header:** avatar, display name + ✓ verified badge, real name (if provided) + Amharic name, **hook line**, vibe tags, location (if provided). Hook + vibe tags come from the **primary channel's** summary (`creators.primary_channel_id`; defaults to the first verified channel, changeable in `/dashboard/channels`). Sections 4–9 render per channel; with several channels they're grouped under a channel switcher (tabs), primary first.
2. **Channels row:** one chip per channel: title, @username, subscriber count, **Open in Telegram** button.
3. **Socials row:** icons for every social. Buttons: **Share** (copy link + share to Telegram via `t.me/share/url`).
4. **Stat cards:** Active since · Posts/week · Longest streak · Night Owl/Early Bird · Language mix · Projects shipped.
5. **Building now:** project cards (name, status pill, 1-liner, link, "mentioned in N posts").
6. **The journey:** a vertical timeline of eras, each with a story and "read the posts" links.
7. **Start here:** 3–5 posts with a one-line why each, linking to `t.me/{u}/{msg_id}`.
8. **In their own words:** up to 2 short quotes with links.
9. **How they work:** stack chips + habits text.
10. **About:** the creator-written bio.
11. **All projects:** full grid (including launched/older).
12. **Footer:** _"Profile generated from public posts, last updated {date}. Every claim links to its source post."_ · Report · "This is me / Edit" (visible to the owner).

SEO: `<title>{Name} (@{channel}) | Buildr</title>`, meta description = hook + intro, canonical URL, OG/Twitter image (§11), `ProfilePage` + `Person` JSON-LD with `sameAs` = socials.

### 9.5 Channel page (`/c/{username}`) (unclaimed)

- **Banner:** _"This page was generated from public posts. Is this your channel? Claim it."_ + "Remove it" link.
- Header: channel avatar, **channel title** (no person name), @username, subscribers, the channel's own description, hook, vibe tags.
- Stat cards, Building now (projects mentioned), journey (channel-level), Start here, In their own words (max 2).
- No About, no How they work, no real name, no socials (unless the channel description itself links them).
- `noindex` until the admin publishes it; indexed after publishing.

### 9.6 Tag page (`/tags/{slug}`)

Tag name + description (curated tags), then a discover grid pre-filtered by the tag.

### 9.7 Claim (`/claim`) & Remove (`/remove`)

- Claim: explainer (what you get, what we read, you control everything) → "Login with Telegram to start".
- Remove: explainer (what gets deleted, blocklist) → login → code flow.

### 9.8 Static & system pages

About, Terms, Privacy (what we collect: Telegram login fields, public posts; what we don't: private chats, account access), Takedown policy (response within 72h), 404 (with search bar), banned page, maintenance page.

### 9.9 Report modal (on every profile/channel page)

Reason select (§3 reports) + details + optional contact; logged-in users are auto-attached. Rate limit: 5/hour per IP. Admin is notified on Telegram.

---

## 10. Admin Panel

### 10.1 Dashboard (`/admin`)

Counters: pending reviews, open reports, pending disputes, failed jobs, scraping paused?, AI spend today vs budget. Charts: signups/day, published profiles, views/day.

### 10.2 Review queue (`/admin/review`)

- List: pending creators + `failed_verification` summaries + field changes (name/slug), oldest first, with flags.
- **Flags (auto):** possible impersonation (display name ≈ an existing creator, trigram > 0.7), low content, sensitive items dropped by the verifier, new account + huge channel, recently renamed channel.
- **Detail page:** left = exact public preview; right = verification report, dropped claims, creator edits vs AI original, channel info, claim proof (code, when, where found).
- **Actions:**
    - Approve
    - Approve + Feature
    - Request changes (templated notes + free text)
    - Reject (templated reasons)
    - Hide a section
    - Edit a section (logged)
    - Regenerate
- Every action DMs the creator and writes an audit log.

### 10.3 Channels (`/admin/channels`)

- Table: username, title, subscribers, claim status, visibility, availability, scrape status, last scraped, cost so far.
- **Add channel / bulk add:** paste a list of handles → each one is validated (§6.2 pre-checks) → `FetchChannelInfo` + backfill → after processing it lands in the review queue as unclaimed → admin publishes.
- **Per channel:** rescrape, reprocess, regenerate summary, view raw posts + extractions, edit timezone, hide/publish, remove + blocklist, view the summary version history (with diffs).

### 10.4 Creators (`/admin/creators`)

Search, view, edit, unpublish, feature/unfeature, ban user (with reason; hides the profile), force a slug change, merge duplicates, view login history.

### 10.5 Claims & disputes (`/admin/claims`)

All claims with status. **Disputes:** when someone claims an already-claimed channel, both parties are shown; resolve by asking both to post a fresh code. Whoever's code appears wins; the loser is detached.

### 10.6 Reports (`/admin/reports`)

Queue with target preview; actions: dismiss, edit content, hide section, unpublish, remove + blocklist, ban. Resolution note required; the reporter is DM'd if logged in.

### 10.7 Jobs (`/admin/jobs`)

Horizon on staging/production (Redis) linked from here; the page itself also reads `failed_jobs` so it works locally on the database queue: retry, delete, pause scraping (global kill switch), canary status.

### 10.8 AI costs (`/admin/ai-costs`)

Spend by day, by job type, by channel; current budget; edit the budget; pause/resume AI jobs.

### 10.9 Featured, Tags, Blocklist, Audit

- **Featured:** drag to reorder the landing carousel.
- **Tags:** curate, merge duplicate AI tags, set descriptions.
- **Blocklist:** add/remove usernames with a reason.
- **Audit:** filterable log of all admin actions.

---

## 11. OG Images, SEO, Sharing

- An OG image per published profile/channel (1200×630): avatar, name, @channel, hook, 3 vibe tags, subscriber count, brand. Generated with Intervention Image from a template; stored in the bucket; regenerated on publish/summary change; cache-busted with a version query param.
- `sitemap.xml`: published creator, channel, and tag pages; regenerated daily.
- `robots.txt`: disallow `/admin`, `/dashboard`, `/onboarding`, `/api`.
- Telegram link previews use OG tags, so test with the bot-based preview (paste the link in Saved Messages).
- Share buttons: copy link, Telegram share, X share.

---

## 12. Security, Abuse, Privacy

### 12.1 Security

- Telegram auth hash verification + `auth_date` window (§5.1).
- CSRF on all forms; Inertia handles the XSRF token.
- Post text is rendered **escaped** or through a strict HTML sanitizer (allowlist: `a, b, i, code, pre, br`), and every link gets `rel="nofollow noopener ugc"`.
- Creator bio: plain text with auto-linked URLs only.
- Admin: 2FA, throttled login, audit log, idle timeout.
- The scraper only ever requests `t.me` hosts (hardcoded), so there's no SSRF surface.
- Secrets live in Laravel Cloud env; the bot token is never sent to the frontend.

### 12.2 Abuse controls

- Rate limits: search, suggest, reports, claim checks, regenerations (§6, §9).
- One user can claim at most 5 channels without admin approval of the extras.
- Impersonation flag in review (§10.2).
- Banning a user hides all their profiles and blocks login.

### 12.3 Reserved slugs

`admin, api, login, logout, register, search, discover, tags, tag, c, claim, remove, about, terms, privacy, takedown-policy, dashboard, onboarding, settings, auth, telegram, og, sitemap, robots, static, assets, buildr, help, support, new, me, u, user, users`, plus offensive-word list and a slug regex `^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$`.

### 12.4 Privacy rules (also shown in the Privacy page)

- We only read **public** channel web previews.
- Unclaimed pages never name or profile the person behind the channel.
- Sensitive topics are filtered out at extraction + verification.
- Removal anytime via `/remove` or a report; removed channels are never re-scraped.
- No raw IPs stored (hashed daily for unique views).

---

## 13. Notifications

| Event                                                                      | To        | Channel                                        |
| -------------------------------------------------------------------------- | --------- | ---------------------------------------------- |
| Claim verified                                                             | Creator   | Telegram DM                                    |
| Profile draft ready                                                        | Creator   | Telegram DM                                    |
| Approved / changes requested / rejected                                    | Creator   | Telegram DM                                    |
| Summary auto-updated                                                       | Creator   | Telegram DM (weekly digest, can be turned off) |
| Removal completed                                                          | Requester | Telegram DM                                    |
| New submission / report / dispute / failed job / canary alert / budget hit | Admin     | Telegram DM to the admin chat                  |

All messages are queued; failures are logged and never block the main flow.

---

## 14. Testing Strategy

- **Pest** feature tests per phase (auth hash verification, claim flow, onboarding states, review actions, policies).
- **Two DB drivers in CI** (§1.1): the full suite on SQLite in memory, plus a Postgres job that also runs tests in the `pgsql` group (search ranking, trigram, tsvector).
- **Parser tests** against saved HTML fixtures (normal post, forward, album, poll, service message, edited, views formats, no-preview redirect, user/bot/group pages).
- **AI tests** with recorded responses: schema validation, verification drops unsupported claims, quote verbatim check.
- **Browser smoke tests** (Pest browser plugin or Playwright) for landing → search → profile, and login → onboarding → submit.
- **Manual QA in Telegram's in-app browser** on Android + iOS.

---

## 15. Phased Build Plan

Each phase ends with acceptance criteria. Don't start the next phase until the current one passes.

### Phase 0: Foundation (Day 1)

- [x] New Laravel app with the Inertia React starter kit, TS, Tailwind 4, shadcn/ui, Pest (scaffolded into the repo root; own git repo)
- [x] SQLite locally (`database/database.sqlite`), database queue + cache + sessions
- [x] Starter-kit auth cleanup: disable public registration now; the starter kit's email/password login moves to `/admin/login` in Phase 5 and `/login` becomes the Telegram widget in Phase 4
- [x] CI (GitHub Actions): Pest on SQLite + Pest on a Postgres service with `pg_trgm`, `unaccent` (§1.1)
- [ ] Laravel Cloud project: staging + production environments, Postgres, bucket, queue worker, scheduler _(you: needs your Laravel Cloud account)_
- [ ] Inertia SSR enabled and working on staging _(verified locally with `php artisan inertia:start-ssr`; confirm on staging)_
- [ ] Two Telegram bots (staging, prod) created; `/setdomain` set _(you: BotFather)_
- [x] `config/buildr.php` with all config keys (§1)
- [ ] Nightwatch/Sentry hooked up; logging to a dedicated channel _(dedicated `buildr` log channel done; add Nightwatch on Laravel Cloud)_
- [x] Base layout: nav, footer, theme tokens, mobile-first breakpoints

**Done when:** a hello-world SSR page is live on the staging domain, a queue job runs on Cloud, and the scheduler ticks.

### Phase 1: Data Model + Scraper (Days 2–4)

- [x] All migrations from §3 + models + factories + seeders (reserved slugs, curated tags)
- [x] `TelegramWebClient` (rate limiter, jitter, backoff, UA, t.me-only)
- [x] `ChannelInfoParser` + `ChannelFeedParser` with fixture tests
- [x] Jobs: `FetchChannelInfo`, `BackfillChannelPage`, `IncrementalScrape`, `RefreshRecentViews`
- [x] Avatar download + resize to the bucket
- [x] Language detection, link extraction + classification, hashtags, ad/promo heuristics
- [x] Raw HTML snapshot storage + pruning
- [x] Blocklist enforcement inside jobs
- [x] Canary job + alert stub
- [x] Artisan command `buildr:scrape {username}` for manual testing

**Done when:** `buildr:scrape` on 5 real public channels backfills posts correctly (spot-check 20 posts each against Telegram), handles a no-preview channel and a non-existent one, and the parser tests pass.

### Phase 2: AI Pipeline (Days 5–8)

- [x] `LlmClient` service (model routing, retries, JSON schema validation, `ai_runs` logging, budget check)
- [x] `ExtractPosts` batched job + schema validation
- [x] `ComputeStats` (all §8.4 stats, timezone-aware) with unit tests
- [x] `BuildProjects` (grouping, fuzzy merge, URLs, status, descriptions)
- [ ] Write **3 example profiles by hand** to define the voice _(drafts in `resources/prompts/v1/examples.md`; rewrite them in your voice)_
- [x] `GenerateSummary` map → reduce, both modes, banned-topic rules
- [x] `VerifySummary` deterministic + LLM checks, drop logic, retry, `failed_verification`
- [x] `ProcessChannel` chain + idempotency by `prompt_version`
- [x] Artisan `buildr:process {username}` and `buildr:summary:show {username}` (prints JSON + verification report)

**Done when:** 5 channels produce summaries you'd actually enjoy reading, every citation checks out by hand, quotes are verbatim, no sensitive content appears, unclaimed mode never names a person, and cost per channel is recorded.

### Phase 3: Public Site (Days 9–12)

- [x] Search index builder (`search_documents`) + `BuildSearchIndex` job
- [x] `/api/search/suggest` + `/search` with the ranking from §9.2
- [x] Landing page (§9.1) with featured / trending / tags / recent sections (seeded data)
- [x] Discover page with filters, sorts, cursor pagination, URL state
- [x] Creator profile page (§9.4), all sections + empty states for each
- [x] Channel page (§9.5) with the unclaimed banner
- [x] Tag pages
- [x] Report modal + endpoint + rate limit
- [x] Profile view tracking (hashed, aggregated)
- [x] Static pages: About, Terms, Privacy, Takedown policy, 404

**Done when:** on staging, you can search by real name, channel title, @handle, a pasted t.me link, and an Amharic name and land on the right page; every page renders server-side; pages look right on a phone inside Telegram's in-app browser.

### Phase 4: Creator Auth, Claim & Onboarding (Days 13–16)

- [x] Telegram Login Widget page + callback with full hash verification + tests
- [x] Bot webhook (`/start`, `/stop`, blocked) + `TelegramNotifier`
- [x] Claim system: code generation, pre-checks, manual check endpoint, 5-min auto-check, expiry, disputes
- [x] Onboarding steps 1–6 (§6.3), resumable, live progress polling
- [x] Unclaimed → claimed conversion + regeneration in claimed mode
- [x] Dashboard: status banner, stats, quick actions
- [x] Dashboard profile editor, socials (platform auto-detect), tags
- [x] Summary review: per-section edit / hide / regenerate (limit), override persistence, keep-mine-or-new prompts
- [x] Projects editor (edit, hide, add, reorder)
- [x] Channels manager (add + verify more, rename flow, remove)
- [x] Settings: slug change rules, timezone, notifications, delete account
- [x] `/remove` flow + blocklisting
- [x] Post-publish edit rules (§6.4)

**Done when:** a brand-new Telegram account can log in → claim a channel with a description code → finish onboarding → see its draft → submit; the bot DMs at each step; removing a channel deletes data and blocks re-scraping.

### Phase 5: Admin Panel (Days 17–20)

- [x] `buildr:make-admin` command, `/admin/login`, forced TOTP setup, middleware, idle timeout
- [x] Dashboard counters + charts
- [x] Review queue + detail page with flags + all actions + DMs
- [x] Channels: list, add, **bulk add**, per-channel tools, summary version history
- [x] Creators: manage, feature, ban, merge
- [x] Claims & disputes
- [x] Reports queue
- [x] Jobs monitor + global scrape kill switch
- [x] AI costs + budget control
- [x] Featured ordering, tag curation, blocklist, audit log viewer
- [x] Admin Telegram alerts for submissions, reports, disputes, failures, canary, budget

**Done when:** you can bulk-add 20 big channels, review and publish them as unclaimed, approve a creator submission end-to-end, and resolve a report, all from the panel on your phone, with every action in the audit log.

### Phase 6: Polish, SEO & Hardening (Days 21–23)

- [ ] OG image generator + regeneration hooks; test previews inside Telegram _(generator + hooks done; test previews inside Telegram after deploy)_
- [x] Sitemap, robots, canonical tags, JSON-LD, `noindex` rules
- [x] All rate limits in place and tested
- [x] Sanitization audit (post HTML, bios, links)
- [x] Loading skeletons, empty states, error states for every page
- [ ] Performance: eager loading, response caching for public pages (bust on publish), image sizes, Lighthouse mobile ≥ 90 _(eager loading + denormalized `directory_entries` done; full-page response caching skipped, see Implementation Notes)_
- [ ] Backups confirmed (restore tested once on staging)
- [ ] Full test suite green in CI _(140 tests green locally on SQLite; Postgres job runs in CI)_

**Done when:** a cold mobile load of a profile is fast inside Telegram, shared links preview with the right image, and a restore drill works.

### Phase 7: Seed & Launch (Days 24–28)

- [ ] Write the 3 tone examples into the final prompt version; freeze `prompt_version` 1
- [ ] Bulk-add 30–50 builder channels you know → review each by hand → publish as unclaimed
- [ ] Feature 8–12 strong ones on the landing page
- [ ] DM each creator: _"Your page is already up: {link}. Claim it to add your name, socials and projects."_
- [ ] Launch post on your own channel + X; ask the first claimed creators to share their pages
- [ ] Watch the admin queue, reports, scrape failures, and AI spend daily for the first 2 weeks

**Done when:** it's live in production, at least 10 creators have claimed their pages, and no unresolved reports are older than 72h.

### Phase 8: Post-Launch (v1.1+)

- Topic clustering with pgvector embeddings → richer "what they talk about" section
- Amharic-language summaries (toggle per profile)
- Profiles for builders without a channel
- "Similar builders" recommendations
- Creator analytics page (views, search appearances, referrers): the natural bridge to the Telegram analytics product and a paid tier
- Weekly "builders to watch" digest via the bot
- Embeddable profile badge for creators' sites

---

## 16. Edge Cases Checklist

- [x] Channel with web preview disabled
- [x] Channel that's actually a group, bot, or user account
- [x] Channel renamed after claim
- [x] Channel deleted or banned by Telegram
- [x] Posts deleted after being cited
- [x] Channel with <20 text posts (basic profile)
- [x] Mostly-forwards channel (aggregator) → summary focuses on what it curates
- [x] Mostly-Amharic or mixed-language channel
- [x] Huge channel (50K+ posts) → backfill caps
- [x] Two people claiming one channel (dispute)
- [x] One creator with multiple channels (summary per channel; header from the primary channel's summary, §9.4)
- [x] Co-run channel (both admins want profiles) → v1: one owner; others listed as "also runs" later
- [x] Creator deletes the claim code before we check → auto-check window + clear instructions
- [x] Creator edits a section, then AI regenerates
- [x] Removal request for a channel that has a claimed profile
- [x] Banned user's channel claimed by someone else
- [x] Telegram markup change (canary)
- [x] 429 rate limit storm (global limiter + backoff + kill switch)
- [x] AI budget hit mid-processing (jobs pause and resume cleanly)
- [x] Slug collision with reserved words or an existing slug
- [x] Login widget blocked in some in-app browsers → fallback "open in browser" hint
- [x] Bot DM blocked by the user (`bot_can_message = false`) → dashboard banners instead

---

## 17. Launch Checklist

- [ ] Production bot domain set; webhook live with secret
- [ ] Admin account created with 2FA
- [ ] Budget cap set; alerts tested
- [ ] Canary running
- [ ] Privacy, Terms, Takedown policy published
- [ ] Backups on
- [ ] Sitemap submitted to Google Search Console
- [ ] 30+ published pages, 8+ featured
- [ ] Claim + removal flows tested on a real phone end-to-end

---

## 18. Implementation Notes (as built)

Where the code differs from the plan above, and why.

**Stack**
- Laravel 13 + Inertia v3 React starter kit, Pest 5, PHPStan (Larastan) level 7, Wayfinder route helpers, `vite-plus` (`vp`). PHP **8.4+** (Symfony 8 DomCrawler requires it).
- AI calls go through the **Laravel AI SDK** (`laravel/ai`) instead of a hand-written `LlmClient`. Each step is an agent class in `app/Ai/Agents` with a structured-output schema (`PostExtractor`, `ProjectDescriber`, `MonthDigester`, `ProfileWriter`, `ClaimChecker`). `App\Services\Ai\AiRunner` wraps every call with the budget check and `ai_runs` logging. Models: Haiku 5.5 for extraction/descriptions, Opus 5.5 for digests, the profile and verification. Opus calls send `fallbacks: "default"` (refusal fallback beta header set in `config/ai.php`).
- Fortify serves admin auth under the `/admin` prefix (`/admin/login`, `/admin/two-factor-challenge`, `/admin/logout`). Registration and password reset are disabled; `buildr:make-admin` creates or resets admins. `/login` is the Telegram widget; a local-only "dev login" exists because the widget needs a BotFather-registered domain.

**Data model additions**
- `directory_entries`: one denormalized row per public listing (published creator or published unclaimed channel). Search, discover, tags, landing and the sitemap read from it; `DirectoryIndexer` keeps it in sync. `search_documents` hangs off it (`directory_entry_id`) instead of a polymorphic `searchable_type/id`.
- `featured_items` (polymorphic, sortable) replaces `creators.featured_at`, so unclaimed channels can be featured too (Phase 7).
- `channels.review_status` (pending/approved/rejected) drives the unclaimed-channel review queue; `channels.page_type`, `last_post_at`, `unavailable_since`, `next_retry_at` added.
- `creators.review_note` (changes-requested / rejection note), `creators.pending_changes` (name/slug changes awaiting review), `creators.submitted_at`.
- `claims.purpose` also supports `dispute` and `rename`; `claims.found_in` / `found_msg_id` store the claim proof shown in review.
- New tables: `channel_suggestions` ("Suggest a channel"), `login_events` (admin login history; IPs hashed), `settings` (kill switches, budget override, canary status).
- `summaries.month_digests` stores the map step so retries and per-section regeneration don't redo it. Hook/intro citations live in `summaries.citations` (`hook`, `intro` keys).

**Pipeline**
- `ProcessChannel` is a small state machine (`extract → projects → stats → summary_start → summary_map → summary_reduce → verify → index → og → done`); each run does one bounded step and dispatches the next. A per-channel cache lock prevents overlapping runs; budget pauses release the job until the next day.
- Projects are built before stats so `projects_count` / `projects_shipped` are correct.
- Per-section "Regenerate" writes a new summary version that only replaces that section, then verifies it; verified versions publish automatically, failures go to the review queue.
- Provider is swappable through the SDK (`BUILDR_AI_PROVIDER`). Besides Anthropic, Groq (`openai/gpt-oss-20b` for extraction, `openai/gpt-oss-120b` for the rest) is supported with the **compact budget profile** (`config/buildr.php` `ai.budgets`): smaller batches and trimmed post text, per-agent output caps, rolling tokens-per-minute pacing in `AiRunner`, and retries on short 429s. A real 79-post channel cost about $0.01 end to end on Groq.
- Verifier hardening found with real output: fun facts are checked against `channel_stats` and only screened by the LLM for sensitivity; quotes match on exact words in order (punctuation, emoji, Unicode dashes ignored); checker calls are chunked by cited-text size and get post dates plus the computed stats; "finances" means a person's own money, not products or contests; the writer gets `stat_units` so "per month" can't become "per week".
- Projects are resolved, not just fuzzy-grouped (`ProjectBuilder` + `ProjectResolver` agent): mentions are pre-grouped by name similarity and brand tokens (`useravn.com`, `getravn.xyz` → `ravn`), each candidate is squeezed into a dossier (names, own-domain and nearby links, dated milestones, the sentences that mention it, newest first), and one call merges renames/domain moves/pivots, drops third-party tools and noise, and sets status, URL (only from the candidates' links), description and a one-line `history`. Creator-edited and hidden projects are never touched; the old describer path is the fallback.
- Provider quotas: daily caps switch once to `buildr.ai.fallback_models` and the spent model is remembered until reset; when both are spent, `ProcessChannel` reschedules itself at the same step (resume token, `ai-quota:*` cache keys), the onboarding page says when it resumes, and admin can "Resume now" on /admin/ai-costs.
- Compact profile context squeeze: sampled extraction (240 posts), quarterly digests past 12 months, digest/writer posts as `|`-separated lines with a `*_format` header instead of repeated JSON keys, chart-only stats (`weekly_activity`) kept out of AI inputs.
- The single automatic retry after a failed hook/intro passes the checker's reasons to the writer (`summaries.verification.feedback` on the retry row). Unclaimed mode names politics explicitly; a heavily political channel (tested on @durov) still fails and lands in the review queue, which is the intended outcome.

**Design system**
- Warm grey canvas, white cards (`.surface`, `.surface-fade`), near-black ink, one mint accent (`--brand`), pastel status pairs (`mint/sky/rose/amber/violet` + `-soft`) and a spectrum gradient reserved for progress. Tokens live in `resources/css/app.css` with dark-mode counterparts.
- Type: Inter (light weights for two-tone headlines via `components/brand/display.tsx`) and Geist Mono for handles, ids, dates and counts.
- Hairline glyphs (`components/figures/glyph.tsx`) for feature cards and empty states; the landing figure (`source-figure.tsx`) shows posts wiring to the profile lines they support.

**Public site**
- Profile views are written straight to `profile_views` (insert-or-ignore + increment) instead of cache counters flushed daily; uniqueness still uses a daily-salted hash in cache, so no IPs are stored.
- Full-page response caching was not added: pages carry per-user shared props. The denormalized directory table, SSR and daily rebuild cover the hot paths.
- Bios render as plain text with auto-linked URLs only; post text is rendered as text (the sanitized HTML is stored but never injected).

**Operations**
- Schedules live in `routes/console.php` (claims every 5 min, hourly canary, daily scrapes/snapshots/housekeeping/directory rebuild, weekly view refresh and digest).
- Commands: `buildr:scrape`, `buildr:process`, `buildr:summary:show`, `buildr:make-admin`, `buildr:reindex`, `buildr:dispatch-scrapes`, `buildr:prune-snapshots`, `buildr:weekly-digest`, `buildr:set-webhook`.
- Queue `retry_after` is 960s (database and Redis) because AI jobs may run up to 900s.
- Backfill is batched and parallel: one job fetches up to `scrape.pages_per_job` pages in bursts of `scrape.concurrency` stepped `before=` cursors (overlap-only, never gaps), with the politeness delay between bursts; only the newest `backfill_max_posts` (3,000) are ever stored. HTML snapshots are kept for info/newest pages only. A 3k-post channel backfills in about a minute instead of ~10.
- Local SQLite runs in WAL mode with a busy timeout so the web server and several workers don't hit "database is locked".
