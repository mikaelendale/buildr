export type ChannelChip = {
    username: string;
    title: string;
    subscribers: number | null;
    avatar?: string | null;
    /** The channel's own Buildr page (/{slug} or /c/{username}). */
    url?: string;
    telegram_url?: string;
    is_primary?: boolean;
    available?: boolean;
};

/** A channel on its owner's profile, linking to its own page. */
export type ChannelCard = ChannelChip & {
    url: string;
    hook: string | null;
    vibe_tags: string[];
    description: string | null;
    posts_per_week: number | null;
    active_since: string | null;
    total_posts: number | null;
    projects_count: number;
    last_post_at: string | null;
};

/** Who runs a claimed channel, unless they chose not to be shown. */
export type ChannelOwner = {
    name: string;
    slug: string;
    url: string;
    avatar: string | null;
};

export type ProfileCard = {
    id: number;
    type: 'creator' | 'channel';
    url: string;
    name: string;
    real_name: string | null;
    real_name_am: string | null;
    /** Claimed channels credit their owner (unless hidden). */
    owner?: { name: string; url: string } | null;
    avatar: string | null;
    hook: string | null;
    vibe_tags: string[];
    channels: ChannelChip[];
    subscribers: number;
    is_claimed: boolean;
    is_available: boolean;
};

export type TagChip = {
    slug: string;
    name: string;
    description?: string | null;
};

export type ProjectStatus =
    | 'idea'
    | 'building'
    | 'launched'
    | 'abandoned'
    | 'unknown';

export type ProjectItem = {
    id: number;
    name: string;
    slug: string;
    url: string | null;
    description: string | null;
    /** Renames, domain moves and pivots ("Moved from getravn.xyz…"). */
    history?: string | null;
    aliases?: string[];
    status: ProjectStatus;
    mention_count: number;
    first_seen: string | null;
    last_seen: string | null;
};

export type PostSource = { msg_id: number; url: string };

export type ChannelStats = {
    active_since: string | null;
    posts_per_week: number | null;
    longest_streak_weeks: number | null;
    current_streak_weeks: number | null;
    posting_label: string | null;
    peak_hour: number | null;
    language_mix: Record<string, number> | null;
    projects_shipped: number | null;
    projects_count: number | null;
    total_posts: number | null;
    top_emoji: string | null;
    /** Posts per week, oldest first; the last entry is the current week. */
    weekly_activity?: number[] | null;
};

export type ChannelSection = {
    channel: ChannelChip;
    has_summary: boolean;
    basic: boolean;
    updated_at: string | null;
    intro: string | null;
    stats: ChannelStats | null;
    building_now: {
        project: ProjectItem;
        line: string | null;
        sources: PostSource[];
    }[];
    eras: {
        name: string;
        from: string | null;
        to: string | null;
        story: string;
        sources: PostSource[];
    }[];
    start_here: {
        msg_id: number;
        url: string;
        why: string;
        snippet: string | null;
        date: string | null;
        views: number | null;
    }[];
    in_their_words: {
        msg_id: number;
        url: string;
        quote: string;
        date: string | null;
    }[];
    how_they_work: { text: string; tools: string[] } | null;
    fun_facts: string[];
    projects: ProjectItem[];
    recent_posts?: {
        msg_id: number;
        url: string;
        snippet: string;
        date: string | null;
        views: number | null;
    }[];
};

export type SocialLink = {
    platform: string;
    label: string;
    url: string;
    handle: string | null;
};

export type CreatorProfile = {
    type: 'creator';
    id: number;
    slug: string;
    url: string;
    name: string;
    real_name: string | null;
    real_name_am: string | null;
    location: string | null;
    bio: string | null;
    avatar: string | null;
    verified: boolean;
    hook: string | null;
    vibe_tags: string[];
    tags: TagChip[];
    socials: SocialLink[];
    channels: ChannelChip[];
    channel_cards: ChannelCard[];
    /** Every visible project across the listed channels, each once. */
    projects: (ProjectItem & {
        channel: { username: string; title: string; url: string } | null;
    })[];
    /** Full per-channel content (admin review only; empty on the public page). */
    sections: ChannelSection[];
    updated_at: string | null;
    published_at: string | null;
    og_image: string;
    /** The owner's share card look; its color also tones the cover. */
    card: CardLook;
};

export type ChannelProfile = {
    type: 'channel';
    id: number;
    username: string;
    slug: string | null;
    url: string;
    claimed: boolean;
    owner: ChannelOwner | null;
    /** The owner's other listed channels. */
    more_channels: ChannelChip[];
    name: string;
    description: string | null;
    avatar: string | null;
    subscribers: number | null;
    hook: string | null;
    vibe_tags: string[];
    available: boolean;
    availability: string;
    channels: ChannelChip[];
    sections: ChannelSection[];
    published_at: string | null;
    og_image: string;
    card: CardLook;
};

export type CardTheme = 'light' | 'dark' | 'color';

export type CardColor = 'mint' | 'sky' | 'violet' | 'amber' | 'rose' | 'ink';

export type CardLook = { theme: CardTheme; color: CardColor };

export type Seo = {
    title: string;
    description: string;
    canonical: string;
    image?: string | null;
    noindex?: boolean;
    jsonLd?: Record<string, unknown> | null;
};

export type CursorPage<T> = {
    data: T[];
    next_cursor?: string | null;
    prev_cursor?: string | null;
};
