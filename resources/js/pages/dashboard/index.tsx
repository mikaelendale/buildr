import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight, Check } from 'lucide-react';
import {
    ChannelProgressCard,
    useBuildingChannels,
} from '@/components/creator/channel-progress';
import type { ChannelProgress } from '@/components/creator/channel-progress';
import {
    ConnectTelegramButton,
    TelegramConnected,
    useTelegramLink,
} from '@/components/creator/telegram-connect';
import type { TelegramLink } from '@/components/creator/telegram-connect';
import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';
import PageContainer from '@/components/page-container';
import ChannelChips from '@/components/profile/channel-chips';
import { PortraitDither } from '@/components/profile/profile-cover';
import ShareCardDialog, {
    shareCardSrc,
} from '@/components/profile/share-card-dialog';
import { StatPanel } from '@/components/profile/stat-cards';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CardLook, ChannelChip } from '@/types';

type Props = {
    creator: {
        id: number;
        name: string;
        slug: string;
        url: string;
        avatar: string | null;
        status:
            | 'draft'
            | 'pending_review'
            | 'published'
            | 'changes_requested'
            | 'rejected'
            | 'hidden';
        review_note: string | null;
        pending_changes: Record<string, string | null> | null;
        onboarding_step: number;
        published_at: string | null;
        card: CardLook;
    };
    channels: (ChannelChip & {
        scrape_status: string;
        availability: string;
        posts_per_week: number | null;
    })[];
    pendingClaims: { id: number; username: string; code: string }[];
    views: {
        last7: number;
        last30: number;
        unique30: number;
        daily: { date: string; views: number }[];
    };
    subscribers: number;
    botCanMessage: boolean;
    telegram: TelegramLink;
    building?: ChannelProgress[];
};

// Their dither fades in from the text side and softly at the top and bottom.
const STATUS_DITHER_MASK =
    'linear-gradient(to right, transparent 0%, #000 60%), linear-gradient(to bottom, transparent 0%, #000 22%, #000 78%, transparent 100%)';

const STATUS: Record<
    Props['creator']['status'],
    {
        badge: string;
        variant: 'outline' | 'sky' | 'mint' | 'amber' | 'rose';
        title: string;
        body: string;
    }
> = {
    draft: {
        badge: 'Draft',
        variant: 'outline',
        title: 'Your profile is a draft',
        body: 'Finish onboarding and submit it for review to go live.',
    },
    pending_review: {
        badge: 'In review',
        variant: 'sky',
        title: "We're checking your profile",
        body: "We'll message you on Telegram when it's live.",
    },
    published: {
        badge: 'Live',
        variant: 'mint',
        title: "You're live",
        body: 'Your page is public. Share it!',
    },
    changes_requested: {
        badge: 'Changes requested',
        variant: 'amber',
        title: 'A few changes, then you’re live',
        body: '',
    },
    rejected: {
        badge: 'Not approved',
        variant: 'rose',
        title: 'Your profile was not approved',
        body: '',
    },
    hidden: {
        badge: 'Hidden',
        variant: 'outline',
        title: 'Your profile is hidden',
        body: 'Contact us if you think this is a mistake.',
    },
};

const QUICK: { href: string; title: string; body: string; glyph: GlyphName }[] =
    [
        {
            href: '/dashboard/profile',
            title: 'Edit profile',
            body: 'Name, bio, socials, tags',
            glyph: 'profile',
        },
        {
            href: '/dashboard/summary',
            title: 'AI summary',
            body: 'Edit, hide or regenerate sections',
            glyph: 'spark',
        },
        {
            href: '/dashboard/projects',
            title: 'Projects',
            body: 'Edit, hide, add, reorder',
            glyph: 'project',
        },
    ];

/**
 * Until the bot is connected we can't reach them (no email): no password
 * resets, no updates. Connected but paused: one tap to turn updates on.
 */
function TelegramBanner({
    telegram,
    paused,
    bot,
}: {
    telegram: TelegramLink;
    paused: boolean;
    bot: string;
}) {
    const link = useTelegramLink(telegram);

    return (
        <div className="group flex flex-wrap items-center gap-4 rounded-2xl border border-dashed border-border p-4 sm:p-5">
            <Glyph name="send" className="size-10" />
            {/* Wide enough that on phones the button drops to its own line. */}
            <p className="min-w-[13rem] flex-1 text-sm text-pretty text-muted-foreground">
                {link.connected && !paused ? (
                    <TelegramConnected username={link.username} />
                ) : paused ? (
                    <>
                        <span className="font-medium text-foreground">
                            Updates are paused.
                        </span>{' '}
                        Send /start to the bot to hear about your page again.
                    </>
                ) : (
                    <>
                        <span className="font-medium text-foreground">
                            Connect Telegram.
                        </span>{' '}
                        It&apos;s how you reset your password and hear when your
                        page changes.
                    </>
                )}
            </p>
            {paused ? (
                <Button asChild size="sm" variant="outline">
                    <a
                        href={`https://t.me/${bot}`}
                        target="_blank"
                        rel="noopener"
                    >
                        Open <span className="font-mono">@{bot}</span>
                    </a>
                </Button>
            ) : (
                !link.connected && (
                    <ConnectTelegramButton
                        size="sm"
                        variant="outline"
                        waiting={link.waiting}
                        onStart={link.start}
                    />
                )
            )}
        </div>
    );
}

export default function Dashboard({
    creator,
    channels,
    pendingClaims,
    views,
    subscribers,
    botCanMessage,
    telegram,
    building: initialBuilding = [],
}: Props) {
    // Channels being added (or just finished) update live; when one lands,
    // the page's channels and stats refresh.
    const building = useBuildingChannels(initialBuilding, [
        'building',
        'channels',
        'subscribers',
    ]);

    const { telegramBot } = usePage().props;
    const status = STATUS[creator.status];
    const maxDaily = Math.max(1, ...views.daily.map((d) => d.views));
    const canContinue =
        creator.status === 'draft' || creator.status === 'changes_requested';
    const submitted =
        creator.status === 'pending_review' || creator.status === 'published';

    const checklist = [
        { label: 'Add your channel', done: creator.onboarding_step >= 3 },
        {
            label: 'Verify it’s yours',
            done: creator.onboarding_step >= 4 || channels.length > 0,
        },
        { label: 'Fill in the basics', done: creator.onboarding_step >= 5 },
        { label: 'Review your profile', done: creator.onboarding_step >= 6 },
        { label: 'Submit for review', done: submitted },
        { label: 'Go live', done: creator.status === 'published' },
    ];
    const doneCount = checklist.filter((c) => c.done).length;
    const nextIndex = checklist.findIndex((c) => !c.done);

    return (
        <PageContainer
            title={`Hi, ${creator.name}`}
            description="Your page at a glance."
            actions={
                <Button asChild variant="outline">
                    <a href={creator.url} target="_blank" rel="noopener">
                        View my page
                        <ArrowUpRight />
                    </a>
                </Button>
            }
        >
            {/* Their dither identity (same as their profile cover) on the right. */}
            <div className="surface relative flex min-h-[132px] flex-col gap-4 overflow-hidden p-5 sm:flex-row sm:items-center">
                <PortraitDither
                    seedKey={creator.slug}
                    tone={creator.card.color}
                    avatar={creator.avatar}
                    size={2.5}
                    className="pointer-events-none absolute inset-y-0 right-0 w-3/5 overflow-hidden opacity-60 sm:w-1/2 sm:opacity-100"
                    style={{
                        maskImage: STATUS_DITHER_MASK,
                        WebkitMaskImage: STATUS_DITHER_MASK,
                        maskComposite: 'intersect',
                        WebkitMaskComposite: 'source-in',
                    }}
                />
                <div className="relative min-w-0 flex-1 sm:max-w-[55%]">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant={status.variant}
                            className={
                                status.variant === 'outline'
                                    ? 'text-muted-foreground'
                                    : ''
                            }
                        >
                            {status.badge}
                        </Badge>
                        <p className="font-medium tracking-tight">
                            {status.title}
                        </p>
                    </div>
                    {(creator.review_note ?? status.body) && (
                        <p className="mt-1.5 text-sm text-pretty text-muted-foreground">
                            {creator.review_note ?? status.body}
                        </p>
                    )}
                    {creator.pending_changes &&
                        Object.keys(creator.pending_changes).length > 0 && (
                            <p className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                Waiting for review:
                                {Object.keys(creator.pending_changes).map(
                                    (k) => (
                                        <span
                                            key={k}
                                            className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-[11px]"
                                        >
                                            {k.replace('_', ' ')}
                                        </span>
                                    ),
                                )}
                            </p>
                        )}
                    {/* Lives with the text, never on top of the dither. */}
                    {canContinue && (
                        <Button asChild variant="brand" className="mt-4">
                            <Link href="/onboarding">
                                Continue setup
                                <ArrowRight />
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            {building.length > 0 && (
                <section
                    aria-label="Channels being added"
                    className="space-y-2.5"
                >
                    <div className="flex items-center justify-between">
                        <h2 className="eyebrow">
                            {building.some((b) => !b.ready)
                                ? 'Being added'
                                : 'Just added'}
                        </h2>
                        <Link
                            href="/dashboard/channels"
                            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Channels
                        </Link>
                    </div>
                    <div
                        className={cn(
                            'grid gap-3',
                            building.length > 1 && 'lg:grid-cols-2',
                        )}
                    >
                        {building.map((p) => (
                            <ChannelProgressCard
                                key={p.channel?.id ?? p.channel?.username}
                                progress={p}
                                pageUrl={creator.url}
                            />
                        ))}
                    </div>
                </section>
            )}

            {creator.status !== 'published' && (
                <StatPanel
                    title="Getting live"
                    value={
                        <>
                            {doneCount}
                            <span className="ml-1 text-xs text-muted-foreground">
                                / {checklist.length}
                            </span>
                        </>
                    }
                    footer={
                        <span>
                            {nextIndex >= 0
                                ? `Next: ${checklist[nextIndex].label.toLowerCase()}`
                                : 'All done'}
                        </span>
                    }
                >
                    <div className="h-1 overflow-hidden rounded-full bg-secondary">
                        <div
                            className="bg-spectrum h-full rounded-full transition-[width] duration-500"
                            style={{
                                width: `${(doneCount / checklist.length) * 100}%`,
                            }}
                        />
                    </div>
                    <ol className="mt-4 grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
                        {checklist.map((item, i) => (
                            <li
                                key={item.label}
                                className="flex items-center gap-2.5 text-sm"
                            >
                                {item.done ? (
                                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-mint-soft ring-1 ring-mint/20">
                                        <Check className="size-3 text-mint" />
                                    </span>
                                ) : (
                                    <span
                                        className={cn(
                                            'flex size-5 shrink-0 items-center justify-center rounded-full font-mono text-[10px]',
                                            i === nextIndex
                                                ? 'bg-primary text-primary-foreground'
                                                : 'border border-dashed border-border text-ink-soft',
                                        )}
                                    >
                                        {i + 1}
                                    </span>
                                )}
                                <span
                                    className={cn(
                                        item.done &&
                                            'text-muted-foreground line-through decoration-border',
                                        !item.done &&
                                            i === nextIndex &&
                                            'font-medium',
                                        !item.done &&
                                            i !== nextIndex &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {item.label}
                                </span>
                            </li>
                        ))}
                    </ol>
                </StatPanel>
            )}

            {telegramBot && (!telegram.connected || !botCanMessage) && (
                <TelegramBanner
                    telegram={telegram}
                    paused={telegram.connected && !botCanMessage}
                    bot={telegramBot}
                />
            )}

            {pendingClaims.length > 0 && (
                <section>
                    <h2 className="eyebrow mb-2">Waiting for verification</h2>
                    <ul className="surface divide-y divide-border/70 overflow-hidden">
                        {pendingClaims.map((claim) => (
                            <li key={claim.id}>
                                <Link
                                    href={`/claims/${claim.id}`}
                                    className="group flex items-center gap-3 px-4 py-3.5 text-sm transition-colors hover:bg-background/60"
                                >
                                    <span className="size-2 shrink-0 animate-pulse rounded-full bg-amber motion-reduce:animate-none" />
                                    <span className="min-w-0 flex-1 text-muted-foreground">
                                        Waiting to see{' '}
                                        <code className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-xs text-foreground">
                                            {claim.code}
                                        </code>{' '}
                                        on{' '}
                                        <span className="font-mono text-foreground/80">
                                            @{claim.username}
                                        </span>
                                    </span>
                                    <ArrowRight className="size-4 text-ink-soft transition-transform duration-150 group-hover:translate-x-0.5" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <StatPanel
                    title="Profile views"
                    value={formatCount(views.last7)}
                    footer={
                        <>
                            <span>Last 7 days</span>
                            <span className="tabular font-mono">
                                {formatCount(views.last30)} / 30d
                            </span>
                        </>
                    }
                />
                <StatPanel
                    title="Unique visitors"
                    value={formatCount(views.unique30)}
                    footer={<span>Last 30 days</span>}
                />
                <StatPanel
                    title="Subscribers"
                    value={formatCount(subscribers)}
                    footer={
                        <span>
                            Across {channels.length} channel
                            {channels.length === 1 ? '' : 's'}
                        </span>
                    }
                />
            </div>

            {views.daily.length > 1 && (
                <StatPanel
                    title="Views, last 30 days"
                    value={formatCount(views.last30)}
                    footer={
                        <>
                            <span className="tabular font-mono">
                                {views.daily[0]?.date}
                            </span>
                            <span className="tabular font-mono">
                                {views.daily[views.daily.length - 1]?.date}
                            </span>
                        </>
                    }
                >
                    <div
                        className="flex h-28 items-end gap-[3px]"
                        role="img"
                        aria-label="Daily profile views"
                    >
                        {views.daily.map((d, i) => (
                            <div
                                key={d.date}
                                title={`${d.date}: ${d.views}`}
                                className={cn(
                                    'flex-1 rounded-t-[3px] transition-colors duration-150',
                                    i === views.daily.length - 1
                                        ? 'bg-brand'
                                        : 'bg-foreground/12 hover:bg-foreground/40',
                                )}
                                style={{
                                    height: `${Math.max(4, (d.views / maxDaily) * 100)}%`,
                                }}
                            />
                        ))}
                    </div>
                </StatPanel>
            )}

            <section>
                <div className="mb-2 flex items-center justify-between">
                    <h2 className="eyebrow">Your channels</h2>
                    <Link
                        href="/dashboard/channels"
                        className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        Manage
                    </Link>
                </div>
                {channels.length > 0 ? (
                    <ChannelChips channels={channels} />
                ) : (
                    <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-4">
                        <Glyph name="feed" className="size-10" />
                        <p className="text-sm text-muted-foreground">
                            No verified channel yet.
                        </p>
                    </div>
                )}
            </section>

            {channels.length > 0 && (
                <section className="surface flex flex-col gap-5 p-4 sm:flex-row sm:items-center sm:p-5">
                    <ShareCardDialog
                        type="creator"
                        id={creator.id}
                        profileUrl={creator.url}
                        name={creator.name}
                        card={creator.card}
                        own
                        fresh
                        trigger={
                            <button
                                type="button"
                                className="group relative w-full shrink-0 overflow-hidden rounded-xl bg-secondary ring-1 ring-border/70 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:w-72"
                                aria-label="Open your share card"
                            >
                                <img
                                    src={shareCardSrc(
                                        'creator',
                                        creator.id,
                                        creator.card,
                                    )}
                                    alt=""
                                    loading="lazy"
                                    className="aspect-[1200/630] w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                                />
                            </button>
                        }
                    />
                    <div className="min-w-0 flex-1">
                        <div className="eyebrow">Share card</div>
                        <p className="mt-1.5 font-medium tracking-tight">
                            Post your card to your channel.
                        </p>
                        <p className="mt-1 text-sm text-pretty text-muted-foreground">
                            A halftone print of you, your hook and real stats.
                            Copy it as an image and paste it into Telegram; it
                            links back to your page.
                        </p>
                        <ShareCardDialog
                            type="creator"
                            id={creator.id}
                            profileUrl={creator.url}
                            name={creator.name}
                            card={creator.card}
                            own
                            fresh
                            trigger={
                                <Button size="sm" className="mt-3">
                                    Copy share card
                                </Button>
                            }
                        />
                    </div>
                </section>
            )}

            <section className="grid gap-3 sm:grid-cols-3">
                {QUICK.map((q) => (
                    <Link
                        key={q.href}
                        href={q.href}
                        className="group surface-fade flex flex-col p-5 pb-6 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <Glyph name={q.glyph} className="-ml-1 size-10" />
                        <p className="mt-4 font-medium tracking-tight">
                            {q.title}
                        </p>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {q.body}
                        </p>
                    </Link>
                ))}
            </section>
        </PageContainer>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
};
