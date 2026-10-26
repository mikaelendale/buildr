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
        'provider' => env('BUILDR_AI_PROVIDER', 'anthropic'),
        'extraction_model' => env('BUILDR_AI_EXTRACTION_MODEL', 'claude-haiku-5-5'),
        'synthesis_model' => env('BUILDR_AI_SYNTHESIS_MODEL', 'claude-opus-5-5'),
        'verify_model' => env('BUILDR_AI_VERIFY_MODEL', 'claude-opus-5-5'),
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
        | Lanes: providers the pipeline rotates between, in order (e.g.
        | "gemini,groq"). Each has its own models, budget profile and rate
        | limits; a lane that runs out of quota or is overloaded hands the
        | step to the next. Empty = one lane from the settings above.
        */
        'lanes' => array_values(array_filter(array_map('trim', explode(',', (string) env('BUILDR_AI_LANES', ''))))),

        'lane_options' => [
            'gemini' => [
                'provider' => 'gemini',
                'budget' => 'roomy',
                // Free tier: few requests, lots of tokens per request.
                'tokens_per_minute' => (int) env('BUILDR_AI_GEMINI_TPM', 200000),
                'requests_per_minute' => (int) env('BUILDR_AI_GEMINI_RPM', 8),
                'timeout_seconds' => 240,
                'models' => [
                    'extraction' => env('BUILDR_AI_GEMINI_EXTRACTION_MODEL', 'gemini-3.5-flash-lite'),
                    'synthesis' => env('BUILDR_AI_GEMINI_SYNTHESIS_MODEL', 'gemini-3.5-flash'),
                    'verify' => env('BUILDR_AI_GEMINI_VERIFY_MODEL', 'gemini-3.5-flash'),
                ],
            ],
            'groq' => [
                'provider' => 'groq',
                'budget' => 'compact',
                'tokens_per_minute' => (int) env('BUILDR_AI_GROQ_TPM', env('BUILDR_AI_TOKENS_PER_MINUTE', 7500)),
                'requests_per_minute' => (int) env('BUILDR_AI_GROQ_RPM', 30),
                'timeout_seconds' => 300,
                'models' => [
                    'extraction' => env('BUILDR_AI_GROQ_EXTRACTION_MODEL', 'openai/gpt-oss-20b'),
                    'synthesis' => env('BUILDR_AI_GROQ_SYNTHESIS_MODEL', 'openai/gpt-oss-120b'),
                    'verify' => env('BUILDR_AI_GROQ_VERIFY_MODEL', 'openai/gpt-oss-120b'),
                ],
            ],
            'anthropic' => [
                'provider' => 'anthropic',
                'budget' => 'standard',
                'models' => [
                    'extraction' => 'claude-haiku-5-5',
                    'synthesis' => 'claude-opus-5-5',
                    'verify' => 'claude-opus-5-5',
                ],
            ],
        ],

        // How each step picks a lane: "rotate" takes turns (high-volume steps,
        // so free quotas add up); "prefer" uses the first lane with quota
        // left, in BUILDR_AI_LANES order (one-shot steps that shape the page).
        'roles' => [
            'extraction' => env('BUILDR_AI_ROLE_EXTRACTION', 'rotate'),
            'digest' => env('BUILDR_AI_ROLE_DIGEST', 'rotate'),
            'describe' => env('BUILDR_AI_ROLE_DESCRIBE', 'rotate'),
            'resolve' => env('BUILDR_AI_ROLE_RESOLVE', 'prefer'),
            'writer' => env('BUILDR_AI_ROLE_WRITER', 'prefer'),
            'verify' => env('BUILDR_AI_ROLE_VERIFY', 'prefer'),
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

        // When a model's daily quota is spent, switch once to a model with its
        // own quota before pausing the pipeline (Groq caps tokens per model).
        'fallback_models' => [
            'openai/gpt-oss-120b' => env('BUILDR_AI_FALLBACK_FOR_120B', 'openai/gpt-oss-20b'),
            'openai/gpt-oss-20b' => env('BUILDR_AI_FALLBACK_FOR_20B', 'openai/gpt-oss-120b'),
            // Gemini quotas are per model too. Flash never falls back to a lite
            // model: the writer and fact-checker go to the next lane instead.
            'gemini-3.5-flash' => 'gemini-3-flash-preview',
            'gemini-3.5-flash-lite' => 'gemini-3.1-flash-lite',
        ],

        // Products, models and platforms that are never a channel author's own
        // project, whatever the labeller guessed (first word or brand, lowercase).
        'third_party_names' => [
            'gpt', 'chatgpt', 'openai', 'claude', 'opus', 'sonnet', 'haiku', 'anthropic', 'gemini', 'bard', 'llama',
            'mistral', 'grok', 'deepseek', 'qwen', 'copilot', 'cursor', 'windsurf', 'lovable', 'bolt', 'v0', 'replit',
            'vercel', 'netlify', 'supabase', 'firebase', 'notion', 'figma', 'canva', 'github', 'telegram', 'whatsapp',
            'tiktok', 'youtube', 'instagram', 'chapa', 'telebirr',
        ],

        // USD per million tokens, used for the ai_runs cost estimate.
        'pricing' => [
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
