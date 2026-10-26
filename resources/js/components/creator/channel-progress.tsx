import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, Check, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import ProfileAvatar from '@/components/directory/profile-avatar';
import DitherField from '@/components/figures/dither-field';
import { Badge } from '@/components/ui/badge';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';

export type ChannelProgress = {
    ready: boolean;
    basic: boolean;
    problem: string | null;
    resume_at?: string | null;
    posts?: number;
    finished_recently?: boolean;
    channel: {
        id?: number;
        username: string;
        title: string;
        avatar?: string | null;
    } | null;
    stages: {
        key: string;
        label: string;
        done: boolean;
        detail?: string | null;
    }[];
};

export const PROGRESS_PROBLEMS: Record<string, string> = {
    no_preview:
        "Telegram isn't showing a public web preview for this channel, so we can't read its posts. Make sure the channel is public, or continue with a basic profile.",
    not_found:
        "We can't find this channel on Telegram anymore. Check that it's still public.",
    error: 'Something went wrong while reading this channel. We are retrying automatically.',
    no_channel: 'Add and verify a channel first.',
    queued: "This channel is in line to be read. It usually starts within a minute; you can leave and we'll message you on Telegram.",
    ai_paused:
        "Our AI provider's daily limit is reached, so writing is paused. It picks up where it stopped on its own; you can leave and we'll message you on Telegram.",
};

function problemText(progress: ChannelProgress): string | null {
    if (!progress.problem) {
        return null;
    }

    const base = PROGRESS_PROBLEMS[progress.problem] ?? progress.problem;

    return progress.problem === 'ai_paused' && progress.resume_at
        ? `${base} Resumes around ${new Date(progress.resume_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.`
        : base;
}

function counts(progress: ChannelProgress) {
    const done = progress.stages.filter((s) => s.done).length;

    return {
        done,
        total: Math.max(1, progress.stages.length),
        current: progress.stages.findIndex((s) => !s.done),
    };
}

/**
 * Polls `/api/channels/progress` while any channel is still being built.
 * When one finishes, it says so and refreshes the page's data.
 */
export function useBuildingChannels(
    initial: ChannelProgress[],
    reloadProps: string[] = [],
): ChannelProgress[] {
    const [list, setList] = useState(initial);
    const previous = useRef(initial);
    const working = list.some((p) => !p.ready);
    const reloadKey = reloadProps.join(',');

    useEffect(() => {
        setList(initial);
        previous.current = initial;
    }, [initial]);

    useEffect(() => {
        if (!working) {
            return;
        }

        const timer = window.setInterval(async () => {
            const { ok, data } = await requestJson<{
                channels: ChannelProgress[];
            }>('/api/channels/progress');

            if (!ok) {
                return;
            }

            const finished = data.channels.filter(
                (p) =>
                    p.ready &&
                    previous.current.some(
                        (o) => o.channel?.id === p.channel?.id && !o.ready,
                    ),
            );

            previous.current = data.channels;
            setList(data.channels);

            if (finished.length > 0) {
                finished.forEach((p) =>
                    toast.success(
                        `@${p.channel?.username} is ready. Its section is on your page now.`,
                    ),
                );

                if (reloadKey !== '') {
                    router.reload({ only: reloadKey.split(',') });
                }
            }
        }, 5000);

        return () => window.clearInterval(timer);
    }, [working, reloadKey]);

    return list;
}

/**
 * The full tray used by onboarding: a live dither strip, the channel, a
 * progress bar and every stage with its detail.
 */
export function ChannelProgressTray({
    progress,
}: {
    progress: ChannelProgress;
}) {
    const { done, total, current } = counts(progress);

    return (
        <div className="rounded-[20px] bg-secondary p-1 ring-1 ring-border/60">
            <div className="overflow-hidden rounded-2xl bg-card shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                {/* Live dither while we work; it settles when the profile is ready. */}
                <div className="relative h-16">
                    <DitherField
                        className="absolute inset-0"
                        tone="mint"
                        shape="warp"
                        secondaryShape="simplex"
                        fade="bottom"
                        speed={progress.ready ? 0 : 0.9}
                        size={2}
                    />
                </div>
                <div className="flex items-center justify-between gap-3 px-5 pt-1 pb-3">
                    <span className="text-sm font-medium">
                        {progress.channel ? (
                            <span className="font-mono text-[13px]">
                                @{progress.channel.username}
                            </span>
                        ) : (
                            'Your channel'
                        )}
                    </span>
                    <span className="tabular font-mono text-xs text-muted-foreground">
                        {done}/{total}
                    </span>
                </div>
                <div className="mx-5 h-1 overflow-hidden rounded-full bg-secondary">
                    <div
                        className="bg-spectrum h-full rounded-full transition-[width] duration-700 ease-out"
                        style={{ width: `${(done / total) * 100}%` }}
                    />
                </div>
                <ol className="mt-2 divide-y divide-border/70">
                    {progress.stages.map((stage, i) => (
                        <li
                            key={stage.key}
                            className="flex items-center gap-3 px-5 py-3.5"
                        >
                            <StageIcon
                                done={stage.done}
                                current={i === current}
                            />
                            <span
                                className={cn(
                                    'text-sm',
                                    !stage.done &&
                                        i === current &&
                                        'font-medium',
                                    !stage.done &&
                                        i !== current &&
                                        'text-muted-foreground',
                                )}
                            >
                                {stage.label}
                            </span>
                            {stage.detail && (
                                <span className="tabular ml-auto font-mono text-[11px] text-muted-foreground">
                                    {stage.detail}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
            {progress.posts !== undefined && (
                <div className="px-4 pt-1.5 pb-1 text-xs text-muted-foreground">
                    <span className="tabular font-mono">{progress.posts}</span>{' '}
                    posts collected so far
                </div>
            )}
        </div>
    );
}

function StageIcon({ done, current }: { done: boolean; current: boolean }) {
    if (done) {
        return (
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-mint-soft ring-1 ring-mint/20">
                <Check className="size-3 text-mint" />
            </span>
        );
    }

    return current ? (
        <Loader2 className="size-5 shrink-0 animate-spin text-brand-ink" />
    ) : (
        <span className="size-5 shrink-0 rounded-full border border-dashed border-border" />
    );
}

/**
 * Compact live card for the dashboard: which channel, what it's doing now,
 * a four-step stepper, and a "ready" state once it's on the page.
 */
export function ChannelProgressCard({
    progress,
    pageUrl,
}: {
    progress: ChannelProgress;
    pageUrl?: string;
}) {
    const { done, total, current } = counts(progress);
    const stage = current >= 0 ? progress.stages[current] : null;
    const problem = problemText(progress);
    const name = progress.channel?.username ?? 'channel';

    return (
        <div className="surface overflow-hidden">
            <div className="flex items-center gap-3 p-4">
                <ProfileAvatar
                    name={progress.channel?.title ?? name}
                    src={progress.channel?.avatar}
                    className="size-10 ring-[3px]"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="truncate font-mono text-[13px] font-medium">
                            @{name}
                        </span>
                        {progress.ready ? (
                            <Badge variant="mint">
                                {progress.basic ? 'Basic profile' : 'Ready'}
                            </Badge>
                        ) : (
                            <Badge variant="sky">Being added</Badge>
                        )}
                    </div>
                    <p className="mt-0.5 flex items-center gap-1.5 truncate text-sm text-muted-foreground">
                        {progress.ready ? (
                            'Read and written. It has its own section on your page.'
                        ) : (
                            <>
                                {stage && (
                                    <Loader2 className="size-3.5 shrink-0 animate-spin text-brand-ink" />
                                )}
                                <span className="truncate">
                                    {stage?.label ?? 'Starting'}
                                    {stage?.detail && (
                                        <span className="tabular font-mono text-xs">
                                            {' '}
                                            · {stage.detail}
                                        </span>
                                    )}
                                </span>
                            </>
                        )}
                    </p>
                </div>
                {progress.ready && pageUrl ? (
                    <a
                        href={pageUrl}
                        target="_blank"
                        rel="noopener"
                        className="flex shrink-0 items-center gap-1 rounded-full bg-secondary px-3 py-1.5 text-xs font-medium transition-colors hover:bg-accent"
                    >
                        View
                        <ArrowUpRight className="size-3.5" />
                    </a>
                ) : (
                    <span className="tabular shrink-0 font-mono text-xs text-muted-foreground">
                        {done}/{total}
                    </span>
                )}
            </div>

            <ProgressStepper progress={progress} className="px-4 pb-3" />

            {problem && !progress.ready && (
                <p className="flex gap-2 border-t border-border/70 bg-amber-soft/60 px-4 py-2.5 text-xs text-pretty text-amber">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                    {problem}
                </p>
            )}
        </div>
    );
}

const SHORT_LABELS: Record<string, string> = {
    scraping: 'Collecting posts',
    reading: 'Reading',
    projects: 'Projects',
    writing: 'Writing',
};

/**
 * Four-step stepper: done steps solid mint, the current one breathing.
 */
export function ProgressStepper({
    progress,
    className,
}: {
    progress: ChannelProgress;
    className?: string;
}) {
    const { current } = counts(progress);

    return (
        <div className={cn('grid grid-cols-4 gap-1', className)}>
            {progress.stages.map((s, i) => (
                <div key={s.key} className="min-w-0">
                    <div
                        className={cn(
                            'h-1 rounded-full',
                            s.done
                                ? 'bg-brand'
                                : i === current && !progress.ready
                                  ? 'bg-brand/50 motion-safe:animate-pulse'
                                  : 'bg-secondary',
                        )}
                    />
                    <span
                        className={cn(
                            'mt-1.5 hidden truncate text-[11px] sm:block',
                            s.done || i === current
                                ? 'text-foreground/70'
                                : 'text-ink-soft',
                        )}
                    >
                        {SHORT_LABELS[s.key] ?? s.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * Inline version for a channel row: what's happening now plus the stepper.
 */
export function ChannelProgressInline({
    progress,
}: {
    progress: ChannelProgress;
}) {
    const { current } = counts(progress);
    const stage = current >= 0 ? progress.stages[current] : null;
    const problem = problemText(progress);

    return (
        <div className="w-full rounded-xl bg-background/70 px-3 py-2.5 ring-1 ring-border/60">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {progress.ready ? (
                    <>
                        <Check className="size-3.5 text-mint" />
                        Done. This channel now has its own section on your page.
                    </>
                ) : (
                    <>
                        <Loader2 className="size-3.5 animate-spin text-brand-ink" />
                        <span className="truncate">
                            {stage?.label ?? 'Starting'}
                            {stage?.detail && (
                                <span className="tabular font-mono">
                                    {' '}
                                    · {stage.detail}
                                </span>
                            )}
                        </span>
                    </>
                )}
            </p>
            <ProgressStepper progress={progress} className="mt-2" />
            {problem && !progress.ready && (
                <p className="mt-2 flex gap-1.5 text-xs text-pretty text-amber">
                    <AlertTriangle className="mt-px size-3.5 shrink-0" />
                    {problem}
                </p>
            )}
        </div>
    );
}

export function ProgressProblem({ progress }: { progress: ChannelProgress }) {
    const text = problemText(progress);

    if (!text) {
        return null;
    }

    return (
        <p className="mt-4 flex gap-2.5 rounded-xl bg-amber-soft p-3.5 text-sm text-pretty text-amber ring-1 ring-amber/15">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            {text}
        </p>
    );
}
