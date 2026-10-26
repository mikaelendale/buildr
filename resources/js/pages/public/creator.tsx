import { Link } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight, Eye, Pencil, Users } from 'lucide-react';
import Display from '@/components/brand/display';
import ProfileAvatar from '@/components/directory/profile-avatar';
import Linkify from '@/components/linkify';
import ProfileCover, { BackPill } from '@/components/profile/profile-cover';
import ProfileHeader from '@/components/profile/profile-header';
import ProjectCard from '@/components/profile/project-card';
import ReportDialog from '@/components/profile/report-dialog';
import ShareButton from '@/components/profile/share-button';
import ShareCardDialog from '@/components/profile/share-card-dialog';
import SocialIcon from '@/components/profile/social-icon';
import Seo from '@/components/seo';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCount, formatDate, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChannelCard, CreatorProfile, Seo as SeoProps } from '@/types';

type Props = {
    profile: CreatorProfile;
    isOwner: boolean;
    isPreview: boolean;
    seo: SeoProps;
};

const TAG_TONES = ['sky', 'violet', 'amber'] as const;

/**
 * One channel on its owner's profile. The whole card opens the channel's
 * own page; Telegram is a separate, smaller way out.
 */
function ChannelCardItem({ channel }: { channel: ChannelCard }) {
    const facts = [
        channel.posts_per_week !== null && channel.posts_per_week > 0
            ? `${channel.posts_per_week >= 10 ? Math.round(channel.posts_per_week) : Math.round(channel.posts_per_week * 10) / 10} posts / week`
            : null,
        channel.active_since
            ? `since ${formatMonth(channel.active_since)}`
            : null,
        channel.projects_count > 0
            ? `${channel.projects_count} project${channel.projects_count === 1 ? '' : 's'}`
            : null,
    ].filter(Boolean);

    return (
        <div className="group surface relative flex h-full flex-col gap-4 p-5 transition-[box-shadow,transform] duration-200 ease-out focus-within:ring-[3px] focus-within:ring-ring/50 hover:-translate-y-0.5 hover:shadow-lift">
            <div className="flex items-center gap-3">
                <ProfileAvatar
                    name={channel.title}
                    src={channel.avatar}
                    className="size-12 ring-[3px]"
                />
                <div className="min-w-0 flex-1 leading-tight">
                    <Link
                        href={channel.url}
                        className="block truncate font-medium tracking-tight outline-none after:absolute after:inset-0 after:rounded-[inherit] after:content-['']"
                    >
                        {channel.title}
                    </Link>
                    <div className="mt-0.5 flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
                        <span className="truncate">@{channel.username}</span>
                        {channel.subscribers !== null && (
                            <span className="tabular flex shrink-0 items-center gap-1">
                                · <Users className="size-3" />
                                {formatCount(channel.subscribers)}
                            </span>
                        )}
                    </div>
                </div>
                {channel.is_primary && <Badge variant="mint">Main</Badge>}
            </div>

            {(channel.hook ?? channel.description) ? (
                <p className="line-clamp-3 text-sm leading-relaxed text-pretty text-foreground/80">
                    {channel.hook ?? channel.description}
                </p>
            ) : (
                <p className="text-sm text-ink-soft">Page on its way.</p>
            )}

            {channel.vibe_tags.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {channel.vibe_tags.map((tag, i) => (
                        <Badge
                            key={tag}
                            variant={TAG_TONES[i % TAG_TONES.length]}
                        >
                            {tag}
                        </Badge>
                    ))}
                </div>
            )}

            <div className="mt-auto flex items-center justify-between gap-3 border-t border-border/70 pt-3 text-xs text-muted-foreground">
                <span className="tabular min-w-0 truncate font-mono">
                    {facts.length > 0 ? facts.join(' · ') : 'Fresh channel'}
                </span>
                <span className="flex shrink-0 items-center gap-3">
                    {channel.telegram_url && (
                        <a
                            href={channel.telegram_url}
                            target="_blank"
                            rel="noopener"
                            aria-label={`Open @${channel.username} in Telegram`}
                            className="relative z-10 inline-flex items-center gap-0.5 transition-colors hover:text-foreground"
                        >
                            Telegram
                            <ArrowUpRight className="size-3.5" />
                        </a>
                    )}
                    <span className="inline-flex items-center gap-1 font-medium text-foreground">
                        Open page
                        <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                    </span>
                </span>
            </div>
        </div>
    );
}

export default function CreatorPage({
    profile,
    isOwner,
    isPreview,
    seo,
}: Props) {
    const names = [profile.real_name, profile.real_name_am]
        .filter(Boolean)
        .join(' · ');
    const cards = profile.channel_cards;

    return (
        <>
            <Seo seo={seo} />

            <ProfileCover seedKey={profile.slug} avatar={profile.avatar} />

            <article className="relative mx-auto max-w-[880px] px-5 pt-16 sm:pt-24">
                <div className="flex flex-wrap items-center gap-2">
                    <BackPill />
                    {isPreview && (
                        <span className="inline-flex h-8 items-center gap-1.5 rounded-full bg-amber-soft/95 px-3 text-sm text-amber ring-1 ring-amber/20 backdrop-blur-sm">
                            <Eye className="size-4" />
                            Preview, not public yet
                        </span>
                    )}
                </div>

                <div className="mt-10 space-y-6 sm:mt-14">
                    <ProfileHeader
                        name={profile.name}
                        avatar={profile.avatar}
                        verified={profile.verified}
                        subtitle={names || undefined}
                        hook={profile.hook}
                        vibeTags={profile.vibe_tags}
                        location={profile.location}
                    />

                    {profile.bio && (
                        <p className="max-w-2xl text-sm leading-relaxed whitespace-pre-line text-foreground/80">
                            <Linkify text={profile.bio} />
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-1.5">
                        {profile.socials.map((social) => (
                            <Button
                                key={social.url}
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label={social.label}
                            >
                                <a
                                    href={social.url}
                                    target="_blank"
                                    rel="me nofollow noopener"
                                >
                                    <SocialIcon
                                        platform={social.platform}
                                        className="size-4"
                                    />
                                </a>
                            </Button>
                        ))}
                        {profile.socials.length > 0 &&
                            profile.tags.length > 0 && (
                                <span className="mx-1.5 h-5 w-px bg-border" />
                            )}
                        {profile.tags.map((tag) => (
                            <Link
                                key={tag.slug}
                                href={`/tags/${tag.slug}`}
                                className="rounded-full bg-card px-2.5 py-1 text-xs text-foreground/75 ring-1 ring-border/80 transition-colors hover:text-foreground"
                            >
                                #{tag.name}
                            </Link>
                        ))}
                        <div className="ml-auto flex items-center gap-1.5">
                            <ShareCardDialog
                                type="creator"
                                id={profile.id}
                                profileUrl={profile.url}
                                name={profile.name}
                                own={isOwner}
                                fresh={isOwner}
                            />
                            <ShareButton
                                url={profile.url}
                                text={`${profile.name} on Buildr`}
                            />
                        </div>
                    </div>
                </div>

                <section className="mt-14">
                    <div className="mb-5 flex items-end justify-between gap-3">
                        <Display size="sm" lead="Their">
                            {cards.length === 1 ? 'channel.' : 'channels.'}
                        </Display>
                        {cards.length > 1 && (
                            <span className="tabular font-mono text-xs text-muted-foreground">
                                {cards.length} channels ·{' '}
                                {formatCount(
                                    cards.reduce(
                                        (sum, c) => sum + (c.subscribers ?? 0),
                                        0,
                                    ),
                                )}{' '}
                                subscribers
                            </span>
                        )}
                    </div>
                    {cards.length > 0 ? (
                        <div
                            className={cn(
                                'grid gap-3',
                                cards.length > 1 && 'sm:grid-cols-2',
                            )}
                        >
                            {cards.map((channel) => (
                                <ChannelCardItem
                                    key={channel.username}
                                    channel={channel}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="surface p-5 text-sm text-muted-foreground">
                            No public channels yet.
                        </p>
                    )}
                </section>

                {profile.projects.length > 0 && (
                    <section className="mt-14">
                        <Display size="sm" lead="What they're" className="mb-5">
                            building.
                        </Display>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {profile.projects.map((project) => (
                                <ProjectCard
                                    key={project.id}
                                    project={project}
                                    source={
                                        cards.length > 1 && project.channel
                                            ? {
                                                  label: `@${project.channel.username}`,
                                                  href: project.channel.url,
                                              }
                                            : null
                                    }
                                />
                            ))}
                        </div>
                    </section>
                )}

                <footer className="mt-16 flex flex-col gap-3 border-t border-border/70 pt-6 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <p className="max-w-lg text-pretty">
                        Written from public posts
                        {profile.updated_at
                            ? `, updated ${formatDate(profile.updated_at)}`
                            : ''}
                        . Each channel has its own page, and every line there
                        links to its source post.
                    </p>
                    <div className="flex items-center gap-1.5">
                        <ReportDialog
                            targetType="creator"
                            targetId={profile.id}
                        />
                        {isOwner && (
                            <Button asChild size="sm" variant="outline">
                                <Link href="/dashboard/profile">
                                    <Pencil />
                                    Edit profile
                                </Link>
                            </Button>
                        )}
                    </div>
                </footer>
            </article>
        </>
    );
}
