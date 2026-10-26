import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Eye, Pencil } from 'lucide-react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import Glyph from '@/components/figures/glyph';
import ChannelChips from '@/components/profile/channel-chips';
import ChannelTabs from '@/components/profile/channel-tabs';
import ProfileCover, { BackPill } from '@/components/profile/profile-cover';
import ProfileHeader from '@/components/profile/profile-header';
import ReportDialog from '@/components/profile/report-dialog';
import ShareButton from '@/components/profile/share-button';
import ShareCardDialog from '@/components/profile/share-card-dialog';
import Seo from '@/components/seo';
import { Button } from '@/components/ui/button';
import { formatCount, formatDate } from '@/lib/format';
import type {
    ChannelChip,
    ChannelOwner,
    ChannelProfile,
    Seo as SeoProps,
} from '@/types';

type Props = {
    channel: ChannelProfile;
    isOwner?: boolean;
    isPreview: boolean;
    seo: SeoProps;
};

/** "by Mike": the person behind a claimed channel, linking to their profile. */
function OwnerCredit({ owner }: { owner: ChannelOwner }) {
    return (
        <Link
            href={owner.url}
            className="group inline-flex items-center gap-1.5 rounded-full py-0.5 pr-2 pl-0.5 text-sm text-muted-foreground transition-colors hover:bg-card hover:text-foreground"
        >
            <ProfileAvatar
                name={owner.name}
                src={owner.avatar}
                className="size-5 text-[9px] shadow-none ring-1"
            />
            <span>
                by{' '}
                <span className="font-medium text-foreground/80 group-hover:text-foreground">
                    {owner.name}
                </span>
            </span>
        </Link>
    );
}

/** The owner's other channels, each to its own page. */
function MoreChannels({
    owner,
    channels,
}: {
    owner: ChannelOwner;
    channels: ChannelChip[];
}) {
    return (
        <section className="mt-14">
            <p className="eyebrow mb-3">More from {owner.name}</p>
            <div className="grid gap-2 sm:grid-cols-2">
                {channels.map((c) => (
                    <Link
                        key={c.username}
                        href={c.url ?? `/c/${c.username}`}
                        className="group surface flex items-center gap-3 p-3 transition-shadow hover:shadow-lift"
                    >
                        <ProfileAvatar
                            name={c.title}
                            src={c.avatar}
                            className="size-10"
                        />
                        <div className="min-w-0 flex-1 leading-tight">
                            <div className="truncate text-sm font-medium">
                                {c.title}
                            </div>
                            <div className="truncate font-mono text-[11px] text-muted-foreground">
                                @{c.username}
                                {c.subscribers !== null &&
                                    ` · ${formatCount(c.subscribers)}`}
                            </div>
                        </div>
                        <ArrowRight className="size-4 shrink-0 text-ink-soft transition-transform group-hover:translate-x-0.5" />
                    </Link>
                ))}
                <Link
                    href={owner.url}
                    className="flex items-center justify-between gap-3 rounded-2xl border border-dashed border-border px-4 py-3 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    {owner.name}'s profile
                    <ArrowRight className="size-4" />
                </Link>
            </div>
        </section>
    );
}

export default function ChannelPage({
    channel,
    isOwner = false,
    isPreview,
    seo,
}: Props) {
    const updated = channel.sections[0]?.updated_at ?? null;

    return (
        <>
            <Seo seo={seo} />

            <ProfileCover seedKey={channel.username} avatar={channel.avatar} />

            <article className="relative mx-auto max-w-[880px] px-5 pt-16 sm:pt-24">
                <div className="flex flex-wrap items-center gap-2">
                    <BackPill />
                    {isPreview && (
                        <span className="inline-flex h-8 items-center gap-1.5 rounded-full bg-amber-soft/95 px-3 text-sm text-amber ring-1 ring-amber/20 backdrop-blur-sm">
                            <Eye className="size-4" />
                            {isOwner
                                ? 'Preview, only you can see this'
                                : 'Admin preview, not published'}
                        </span>
                    )}
                </div>

                <div className="mt-10 space-y-6 sm:mt-14">
                    {!channel.available && (
                        <div className="flex items-start gap-2.5 rounded-2xl bg-amber-soft p-4 text-sm text-amber ring-1 ring-amber/15">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            This channel is currently unavailable on Telegram.
                            What you see is from when it was last public.
                        </div>
                    )}

                    <ProfileHeader
                        name={channel.name}
                        avatar={channel.avatar}
                        verified={channel.claimed}
                        subtitle={
                            <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="font-mono text-xs">
                                    @{channel.username}
                                </span>
                                {channel.owner && (
                                    <OwnerCredit owner={channel.owner} />
                                )}
                            </span>
                        }
                        hook={channel.hook}
                        vibeTags={channel.vibe_tags}
                    >
                        {channel.description && (
                            <p className="max-w-2xl text-sm whitespace-pre-line text-muted-foreground">
                                {channel.description}
                            </p>
                        )}
                    </ProfileHeader>

                    <ChannelChips channels={channel.channels} />

                    {!channel.claimed && (
                        <div className="group flex flex-col gap-4 rounded-2xl bg-brand-soft p-4 ring-1 ring-brand-ink/10 sm:flex-row sm:items-center sm:p-5">
                            <Glyph
                                name="claim"
                                className="hidden size-10 sm:block"
                            />
                            <p className="flex-1 text-sm text-pretty">
                                <span className="font-medium">
                                    Is this your channel?
                                </span>{' '}
                                <span className="text-foreground/70">
                                    This page was written from public posts.
                                    Claim it to add your story and links.
                                </span>
                            </p>
                            <div className="flex shrink-0 items-center gap-2">
                                <Link
                                    href={`/remove?channel=${channel.username}`}
                                    className="rounded-full px-3 py-1.5 text-sm text-foreground/60 transition-colors hover:text-foreground"
                                >
                                    Remove it
                                </Link>
                                <Link
                                    href={`/claim?channel=${channel.username}`}
                                    className="inline-flex h-9 items-center gap-1.5 rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground transition-transform duration-150 active:scale-[0.97]"
                                >
                                    Claim it
                                    <ArrowRight className="size-4" />
                                </Link>
                            </div>
                        </div>
                    )}
                </div>

                <div className="mt-8">
                    <ChannelTabs
                        sections={channel.sections}
                        showHowTheyWork={channel.claimed}
                        withNav
                        actions={
                            <div className="flex items-center gap-1.5">
                                <ShareCardDialog
                                    type="channel"
                                    id={channel.id}
                                    profileUrl={channel.url}
                                    name={channel.name}
                                    own={isOwner}
                                    fresh={isOwner}
                                />
                                <ShareButton
                                    url={channel.url}
                                    text={`${channel.name} on Buildr`}
                                />
                            </div>
                        }
                    />
                </div>

                {channel.owner && channel.more_channels.length > 0 && (
                    <MoreChannels
                        owner={channel.owner}
                        channels={channel.more_channels}
                    />
                )}

                <footer className="mt-16 flex flex-col gap-3 border-t border-border/70 pt-6 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <p className="max-w-lg text-pretty">
                        Written from public posts
                        {updated ? `, updated ${formatDate(updated)}` : ''}.
                        Every line links to its source.
                        {!channel.claimed &&
                            ' Unclaimed pages never profile the person behind the channel.'}
                    </p>
                    <div className="flex items-center gap-1.5">
                        <ReportDialog
                            targetType="channel"
                            targetId={channel.id}
                        />
                        {isOwner && (
                            <Button asChild size="sm" variant="outline">
                                <Link href="/dashboard/channels">
                                    <Pencil />
                                    Edit page
                                </Link>
                            </Button>
                        )}
                    </div>
                </footer>
            </article>
        </>
    );
}
