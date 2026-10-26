import { Badge } from '@/components/ui/badge';
import { formatHour, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChannelStats } from '@/types';

/**
 * A white card seated in a grey tray; the tray's lower lip carries the
 * footnote. `glow` tints the tray with the faintest spectrum wash.
 */
export function StatPanel({
    title,
    value,
    children,
    footer,
    glow = false,
    className,
}: {
    title: string;
    value?: React.ReactNode;
    children?: React.ReactNode;
    footer?: React.ReactNode;
    glow?: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-[20px] bg-secondary p-1 ring-1 ring-border/60',
                glow &&
                    'bg-[linear-gradient(120deg,var(--amber-soft),var(--rose-soft)_40%,var(--violet-soft)_70%,var(--sky-soft))]',
                className,
            )}
        >
            <div className="rounded-2xl bg-card p-4 shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-medium">{title}</h3>
                    {value !== undefined && (
                        <div className="tabular text-right text-[22px] leading-none font-light tracking-tight">
                            {value}
                        </div>
                    )}
                </div>
                {children && <div className="mt-4">{children}</div>}
            </div>
            {footer && (
                <div className="flex min-h-9 items-center justify-between gap-2 px-3 pt-1.5 pb-0.5 text-xs text-muted-foreground">
                    {footer}
                </div>
            )}
        </div>
    );
}

/**
 * Spectrum progress with a ticked remainder and a dot at the head.
 */
export function TickBar({
    value,
    ticks = 22,
}: {
    value: number;
    ticks?: number;
}) {
    const pct = Math.max(0, Math.min(1, value));

    return (
        <div className="flex h-3 items-center gap-2" aria-hidden="true">
            <div
                className="bg-spectrum relative h-1.5 rounded-full"
                style={{ width: `${Math.max(pct * 100, 6)}%` }}
            >
                <span className="absolute top-1/2 right-0 size-2.5 translate-x-1/2 -translate-y-1/2 rounded-full bg-rose shadow-[0_0_0_2px_var(--card)]" />
            </div>
            <div className="flex flex-1 justify-between gap-[3px] overflow-hidden pl-1.5">
                {Array.from({ length: Math.round(ticks * (1 - pct)) }).map(
                    (_, i) => (
                        <span
                            key={i}
                            className="h-2.5 w-px shrink-0 rounded-full bg-border"
                        />
                    ),
                )}
            </div>
        </div>
    );
}

/** 112.2 → "112", 3.4 → "3.4": precision only where it means something. */
export function formatRate(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return value >= 10
        ? Math.round(value).toLocaleString('en')
        : String(Math.round(value * 10) / 10);
}

function weeksAgo(i: number, total: number): string {
    const ago = total - 1 - i;

    return ago === 0
        ? 'this week'
        : ago === 1
          ? 'last week'
          : `${ago} weeks ago`;
}

/**
 * One mint bar per week, scaled to the busiest week in view; the current
 * week is the ink-accented bar on the right. Quiet weeks keep a hairline.
 */
export function ActivityBars({
    weeks,
    className,
}: {
    weeks: number[];
    className?: string;
}) {
    const max = Math.max(1, ...weeks);

    return (
        <div
            className={cn('flex h-16 items-end gap-[3px]', className)}
            role="img"
            aria-label={`Posts per week over the last ${weeks.length} weeks, up to ${max} in a week`}
        >
            {weeks.map((count, i) => {
                const current = i === weeks.length - 1;

                return (
                    <span
                        key={i}
                        title={`${count.toLocaleString('en')} post${count === 1 ? '' : 's'} · ${weeksAgo(i, weeks.length)}`}
                        className={cn(
                            'min-w-0 flex-1 rounded-[3px] transition-[height] duration-500',
                            count === 0
                                ? 'bg-border'
                                : current
                                  ? 'bg-brand-ink'
                                  : 'bg-chart-1/70 hover:bg-chart-1',
                        )}
                        style={{
                            height:
                                count === 0
                                    ? '2px'
                                    : `${Math.max(8, (count / max) * 100)}%`,
                        }}
                    />
                );
            })}
        </div>
    );
}

function Fact({
    label,
    value,
    hint,
}: {
    label: string;
    value: React.ReactNode;
    hint?: React.ReactNode;
}) {
    return (
        <div className="surface flex flex-col p-4">
            <div className="eyebrow">{label}</div>
            <div className="mt-2 text-[20px] leading-tight font-light tracking-tight">
                {value}
            </div>
            {hint && (
                <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
            )}
        </div>
    );
}

/**
 * Stats render straight from channel_stats, never from the LLM (plan §8.6).
 */
export default function StatCards({ stats }: { stats: ChannelStats }) {
    const languages = Object.entries(stats.language_mix ?? {})
        .filter(([, pct]) => pct > 0)
        .sort((a, b) => b[1] - a[1]);
    const weeks = stats.weekly_activity ?? [];
    const recent = weeks.reduce((sum, n) => sum + n, 0);
    const shipped = stats.projects_shipped ?? 0;
    const tracked = stats.projects_count ?? 0;

    return (
        <div className="space-y-3">
            <div className="rounded-[20px] bg-secondary p-1 ring-1 ring-border/60">
                <div className="rounded-2xl bg-card p-5 shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                        <div>
                            <div className="eyebrow">Posting activity</div>
                            <div className="mt-2 flex items-baseline gap-2">
                                <span className="tabular text-[34px] leading-none font-light tracking-[-0.03em]">
                                    {formatRate(stats.posts_per_week)}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    posts a week
                                </span>
                            </div>
                        </div>
                        {weeks.length > 0 && (
                            <div className="tabular font-mono text-[11px] text-muted-foreground">
                                {recent.toLocaleString('en')} posts in{' '}
                                {weeks.length} weeks
                            </div>
                        )}
                    </div>

                    {weeks.length > 0 && (
                        <>
                            <ActivityBars weeks={weeks} className="mt-5" />
                            <div className="mt-2 flex justify-between font-mono text-[10px] text-ink-soft">
                                <span>{weeks.length} weeks ago</span>
                                <span>this week</span>
                            </div>
                        </>
                    )}
                </div>
                <div className="flex min-h-9 flex-wrap items-center gap-x-4 gap-y-1 px-3 pt-1.5 pb-0.5 text-xs text-muted-foreground">
                    <span>
                        Active since{' '}
                        <span className="text-foreground/80">
                            {formatMonth(stats.active_since) || '—'}
                        </span>
                    </span>
                    <span>
                        Longest streak{' '}
                        <span className="tabular text-foreground/80">
                            {stats.longest_streak_weeks
                                ? `${stats.longest_streak_weeks} wk`
                                : '—'}
                        </span>
                    </span>
                    {stats.current_streak_weeks ? (
                        <Badge variant="mint" className="ml-auto">
                            {stats.current_streak_weeks}-week streak going
                        </Badge>
                    ) : null}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Fact
                    label="Busiest time"
                    value={stats.posting_label ?? '—'}
                    hint={
                        stats.peak_hour !== null
                            ? `Peaks around ${formatHour(stats.peak_hour)}`
                            : undefined
                    }
                />
                <Fact
                    label="Projects"
                    value={
                        <>
                            <span className="tabular">{shipped}</span>{' '}
                            <span className="text-sm text-muted-foreground">
                                shipped
                            </span>
                        </>
                    }
                    hint={tracked ? `${tracked} tracked in posts` : undefined}
                />
                <Fact
                    label="Language"
                    value={
                        languages[0]
                            ? (LANG[languages[0][0]] ?? languages[0][0])
                            : '—'
                    }
                    hint={
                        languages.length > 0
                            ? languages
                                  .slice(0, 2)
                                  .map(
                                      ([lang, pct]) =>
                                          `${pct}% ${LANG[lang] ?? lang}`,
                                  )
                                  .join(' · ')
                            : undefined
                    }
                />
                <Fact
                    label="Posts read"
                    value={
                        <span className="tabular">
                            {(stats.total_posts ?? 0).toLocaleString('en')}
                        </span>
                    }
                    hint={
                        stats.top_emoji
                            ? `Favourite emoji ${stats.top_emoji}`
                            : undefined
                    }
                />
            </div>
        </div>
    );
}

const LANG: Record<string, string> = {
    en: 'English',
    am: 'Amharic',
    mixed: 'Mixed',
    other: 'Other',
};
