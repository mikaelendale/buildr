<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claims
    |--------------------------------------------------------------------------
    |
    | Claim codes prove channel ownership. The creator pastes the code into
    | the channel description (or a post) and the scraper looks for it.
    |
    */

    'claim_code_prefix' => env('BUILDR_CLAIM_CODE_PREFIX', 'BUILDR'),

    'claim_code_ttl_hours' => (int) env('BUILDR_CLAIM_CODE_TTL_HOURS', 48),

    'max_channels_per_user' => (int) env('BUILDR_MAX_CHANNELS_PER_USER', 5),

    /*
    |--------------------------------------------------------------------------
    | Scraping
    |--------------------------------------------------------------------------
    */

    'scrape' => [
        // Gap between request bursts (one burst = up to `concurrency` pages).
        'min_delay_ms' => (int) env('BUILDR_SCRAPE_MIN_DELAY_MS', 900),
        'jitter_ms' => (int) env('BUILDR_SCRAPE_JITTER_MS', 400),
        'concurrency' => (int) env('BUILDR_SCRAPE_CONCURRENCY', 4),
        'pages_per_job' => (int) env('BUILDR_SCRAPE_PAGES_PER_JOB', 40),
        // Hard cap: only the newest N posts of a channel are ever stored.
        'backfill_max_posts' => (int) env('BUILDR_SCRAPE_BACKFILL_MAX_POSTS', 3000),
        'backfill_max_months' => (int) env('BUILDR_SCRAPE_BACKFILL_MAX_MONTHS', 36),
        'max_consecutive_failures' => 5,
        'backoff_minutes' => [1, 5, 30, 120],
        'snapshot_retention_days' => 30,
        'user_agent' => env('BUILDR_SCRAPE_USER_AGENT', 'BuildrBot/1.0 (+'.env('APP_URL', 'http://localhost').'/about)'),
        'canary_channel' => env('BUILDR_CANARY_CHANNEL', 'telegram'),
        'snapshot_disk' => env('BUILDR_SNAPSHOT_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */

    'ai' => [
        // Calls go through the Laravel AI SDK (config/ai.php holds the provider + key).
        // These four settings make one lane; BUILDR_AI_LANES below replaces them.
        // Default: OpenAI's small models. gpt-5-nano labels posts and writes
        // digests (high volume, minimal reasoning); gpt-5.6-luna writes,
        // matches projects and fact-checks with a little reasoning
        // (ConfiguresBuildrAgent). About a cent and two minutes per channel.
        'provider' => env('BUILDR_AI_PROVIDER', 'openai'),
        'extraction_model' => env('BUILDR_AI_EXTRACTION_MODEL', 'gpt-5-nano'),
        'synthesis_model' => env('BUILDR_AI_SYNTHESIS_MODEL', 'gpt-5.6-luna'),
        'verify_model' => env('BUILDR_AI_VERIFY_MODEL', 'gpt-5.6-luna'),
        'prompt_version' => (int) env('BUILDR_AI_PROMPT_VERSION', 1),
        'daily_budget_usd' => (float) env('BUILDR_AI_DAILY_BUDGET_USD', 10),
        'extraction_batches_per_job' => 5,
        'months_per_job' => 6,
        'timeout_seconds' => 600,

        /*
        | How much context each call gets. "standard" suits large-context paid
        | models; "compact" keeps every request well under small per-minute
        | token limits (e.g. Groq's free tier: 8K tokens/minute, where the
        | requested max output counts against the limit too).
        */
        'budget_profile' => env('BUILDR_AI_BUDGET_PROFILE', 'standard'),

        /*
        | Smart routing (AiLanes): the providers to use (e.g. "gemini,groq").
        | Every call picks the best route below for the step: allowed quality
        | tier first (strong models for the writer, fact-checker and project
        | resolver; light ones for labelling and digests), then recent health,
        | headroom this minute and against the daily cap, taking turns between
        | equals. A route that fails hands over to the next best. Empty = one
        | lane from the classic settings above.
        */
        'lanes' => array_values(array_filter(array_map('trim', explode(',', (string) env('BUILDR_AI_LANES', ''))))),

        // tier: 1 strong, 2 solid, 3 light. rpm/tpm/rpd: free-tier limits
        // (0 = unknown); quotas are per model, so every route adds capacity.
        'routes' => [
            ['provider' => 'gemini', 'model' => 'gemini-3.5-flash', 'tier' => 1, 'budget' => 'roomy', 'rpm' => 8, 'tpm' => 200000, 'timeout' => 240],
            ['provider' => 'gemini', 'model' => 'gemini-3.6-flash', 'tier' => 1, 'budget' => 'roomy', 'rpm' => 8, 'tpm' => 200000, 'timeout' => 240],
            ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'tier' => 1, 'budget' => 'roomy', 'rpm' => 5, 'rpd' => 20, 'tpm' => 200000, 'timeout' => 240],
            ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b', 'tier' => 2, 'budget' => 'compact', 'rpm' => 30, 'tpm' => 7500, 'timeout' => 300],
            ['provider' => 'groq', 'model' => 'qwen/qwen3.8-27b', 'tier' => 2, 'budget' => 'compact', 'rpm' => 30, 'tpm' => 6000, 'timeout' => 300],
            ['provider' => 'gemini', 'model' => 'gemma-4-31b-it', 'tier' => 2, 'budget' => 'compact', 'rpm' => 15, 'tpm' => 15000, 'timeout' => 240, 'thinking' => false],
            ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'tier' => 3, 'budget' => 'roomy', 'rpm' => 15, 'tpm' => 250000, 'timeout' => 240],
            ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite', 'tier' => 3, 'budget' => 'roomy', 'rpm' => 15, 'tpm' => 250000, 'timeout' => 240],
            ['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite-preview', 'tier' => 3, 'budget' => 'roomy', 'rpm' => 15, 'tpm' => 250000, 'timeout' => 240],
            ['provider' => 'groq', 'model' => 'openai/gpt-oss-20b', 'tier' => 3, 'budget' => 'compact', 'rpm' => 30, 'tpm' => 7500, 'timeout' => 300],
            ['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'tier' => 1, 'budget' => 'standard'],
            ['provider' => 'openai', 'model' => 'gpt-5-nano', 'tier' => 3, 'budget' => 'standard'],
            ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'tier' => 1, 'budget' => 'standard'],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'tier' => 3, 'budget' => 'standard'],
        ],

        'budgets' => [
            'standard' => [
                'extraction_batch_size' => 40,
                'extraction_text_chars' => 1500,
                'extraction_max_posts' => 0,
                'resolver_max_candidates' => 60,
                'resolver_snippets' => 4,
                'resolver_snippet_chars' => 220,
                'digest_max_posts' => 120,
                'max_digests' => 0,
                'compact_lines' => 0,
                'digest_snippet_chars' => 160,
                'digest_top_posts' => 5,
                'digest_top_chars' => 1500,
                'writer_max_posts' => 60,
                'writer_post_chars' => 900,
                'writer_max_digests' => 36,
                'writer_digest_chars' => 3600,
                'writer_examples' => 3,
                'verify_post_chars' => 1500,
                'verify_chars_per_call' => 0,
                'max_output_tokens' => ['extraction' => 16000, 'digest' => 4000, 'writer' => 16000, 'verify' => 8000, 'describe' => 4000, 'resolve' => 8000],
                'tokens_per_minute' => 0,
            ],
            'compact' => [
                'extraction_batch_size' => 8,
                'extraction_text_chars' => 450,
                'extraction_max_posts' => (int) env('BUILDR_AI_EXTRACTION_MAX_POSTS', 240),
                'resolver_max_candidates' => 20,
                'resolver_snippets' => 3,
                'resolver_snippet_chars' => 200,
                'digest_max_posts' => 30,
                'max_digests' => 12,
                'compact_lines' => 1,
                'digest_snippet_chars' => 90,
                'digest_top_posts' => 3,
                'digest_top_chars' => 450,
                'writer_max_posts' => 14,
                'writer_post_chars' => 320,
                'writer_max_digests' => 10,
                'writer_digest_chars' => 320,
                'writer_examples' => 1,
                'verify_post_chars' => 1500,
                'verify_chars_per_call' => 6000,
                'max_output_tokens' => ['extraction' => 1200, 'digest' => 1400, 'writer' => 3600, 'verify' => 2600, 'describe' => 1000, 'resolve' => 2400],
                'tokens_per_minute' => (int) env('BUILDR_AI_TOKENS_PER_MINUTE', 8000),
            ],
            // Large context, few requests per day (Gemini's free tier): big
            // batches and rich evidence per call. Output caps include thinking.
            'roomy' => [
                'extraction_batch_size' => 40,
                'extraction_text_chars' => 1200,
                'extraction_max_posts' => (int) env('BUILDR_AI_ROOMY_EXTRACTION_MAX_POSTS', 1200),
                'resolver_max_candidates' => 60,
                'resolver_snippets' => 4,
                'resolver_snippet_chars' => 240,
                'digest_max_posts' => 80,
                'max_digests' => 24,
                'compact_lines' => 0,
                'digest_snippet_chars' => 220,
                'digest_top_posts' => 5,
                'digest_top_chars' => 1200,
                'writer_max_posts' => 60,
                'writer_post_chars' => 900,
                'writer_max_digests' => 36,
                'writer_digest_chars' => 1500,
                'writer_examples' => 3,
                'verify_post_chars' => 1500,
                'verify_chars_per_call' => 0,
                'max_output_tokens' => ['extraction' => 12000, 'digest' => 3000, 'writer' => 16000, 'verify' => 8000, 'describe' => 3000, 'resolve' => 8000],
                'tokens_per_minute' => 0,
            ],
        ],

        // Classic single-lane setups: when a model's daily quota is spent, switch
        // to a model with its own quota before pausing (routing doesn't need this).
        'fallback_models' => [
            'openai/gpt-oss-120b' => env('BUILDR_AI_FALLBACK_FOR_120B', 'openai/gpt-oss-20b'),
            'openai/gpt-oss-20b' => env('BUILDR_AI_FALLBACK_FOR_20B', 'openai/gpt-oss-120b'),
        ],

        // Products, models and platforms that are never a channel author's own
        // project, whatever the labeller guessed (first word or brand, lowercase).
        'third_party_names' => [
            'gpt', 'chatgpt', 'openai', 'claude', 'opus', 'sonnet', 'haiku', 'anthropic', 'gemini', 'bard', 'llama',
            'mistral', 'grok', 'deepseek', 'qwen', 'copilot', 'cursor', 'windsurf', 'lovable', 'bolt', 'v0', 'replit',
            'vercel', 'netlify', 'supabase', 'firebase', 'notion', 'figma', 'canva', 'github', 'telegram', 'whatsapp',
            'tiktok', 'youtube', 'instagram', 'chapa', 'telebirr',
        ],

        // USD per million tokens (standard tier), used for the ai_runs cost
        // estimate and the daily budget. cache_read is the cached-input rate;
        // reasoning tokens bill as output. OpenAI rates checked 2026-10-27
        // against developers.openai.com/api/docs/pricing.
        'pricing' => [
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40, 'cache_read' => 0.005],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00, 'cache_read' => 0.025],
            'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25, 'cache_read' => 0.02],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50, 'cache_read' => 0.075],
            'gpt-5.4' => ['input' => 2.50, 'output' => 15.00, 'cache_read' => 0.25],
            'gpt-5.5' => ['input' => 5.00, 'output' => 30.00, 'cache_read' => 0.50],
            'gpt-5.6-luna' => ['input' => 0.20, 'output' => 1.20, 'cache_read' => 0.02],
            'gpt-5.6-terra' => ['input' => 2.00, 'output' => 12.00, 'cache_read' => 0.20],
            'gpt-5.6-sol' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.40],
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60, 'cache_read' => 0.10],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cache_read' => 0.075],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20],
            'openai/gpt-oss-20b' => ['input' => 0.075, 'output' => 0.30, 'cache_read' => 0.0375],
            'openai/gpt-oss-120b' => ['input' => 0.15, 'output' => 0.60, 'cache_read' => 0.075],
            'qwen/qwen3.8-27b' => ['input' => 0.29, 'output' => 0.59, 'cache_read' => 0.145],
            // Free tier costs nothing; these are paid-tier estimates so the daily budget stays conservative.
            'gemini-3.5-flash' => ['input' => 0.30, 'output' => 2.50, 'cache_read' => 0.03],
            'gemini-3-flash-preview' => ['input' => 0.30, 'output' => 2.50, 'cache_read' => 0.03],
            'gemini-3.5-flash-lite' => ['input' => 0.10, 'output' => 0.40, 'cache_read' => 0.01],
            'gemini-3.1-flash-lite' => ['input' => 0.10, 'output' => 0.40, 'cache_read' => 0.01],
            'gemini-3.1-flash-lite-preview' => ['input' => 0.10, 'output' => 0.40, 'cache_read' => 0.01],
            'gemini-3.6-flash' => ['input' => 0.30, 'output' => 2.50, 'cache_read' => 0.03],
            'gemma-4-31b-it' => ['input' => 0.10, 'output' => 0.40, 'cache_read' => 0.01],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    'content' => [
        'min_text_posts_for_profile' => (int) env('BUILDR_MIN_TEXT_POSTS_FOR_PROFILE', 20),
        'regen_new_posts_threshold' => 15,
    ],

    'regen' => [
        'creator_daily_limit' => (int) env('BUILDR_REGEN_CREATOR_DAILY_LIMIT', 3),
    ],

    'default_timezone' => env('BUILDR_DEFAULT_TIMEZONE', 'Africa/Addis_Ababa'),

    // Public contact handle shown in the footer and takedown policy (without @).
    'support_telegram' => env('BUILDR_SUPPORT_TELEGRAM'),

    // "Made with love by" credit in the public footer.
    'maker' => [
        'handle' => env('BUILDR_MAKER_HANDLE', 'mikaelendale'),
        'url' => env('BUILDR_MAKER_URL', 'https://mikaelen.site'),
        'links' => [
            ['platform' => 'telegram', 'label' => 'Telegram', 'url' => env('BUILDR_MAKER_TELEGRAM', 'https://t.me/mike_endale')],
            ['platform' => 'x', 'label' => 'X', 'url' => env('BUILDR_MAKER_X', 'https://x.com/mikael_endale')],
            ['platform' => 'github', 'label' => 'GitHub', 'url' => env('BUILDR_MAKER_GITHUB', 'https://github.com/mikaelendale')],
            ['platform' => 'substack', 'label' => 'Substack', 'url' => env('BUILDR_MAKER_SUBSTACK', 'https://mikaelendale.substack.com')],
            ['platform' => 'website', 'label' => 'Website', 'url' => env('BUILDR_MAKER_URL', 'https://mikaelen.site')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | One bot powers the Login Widget and DM notifications. It never reads
    | channels: all channel data comes from public t.me web previews.
    |
    */

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'auth_max_age_seconds' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    'admin' => [
        'idle_timeout_minutes' => 120,
    ],

];
