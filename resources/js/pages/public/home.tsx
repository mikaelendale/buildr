import { Head, Link } from '@inertiajs/react';
import { ArrowDown, ArrowRight } from 'lucide-react';
import Display from '@/components/brand/display';
import DitherField from '@/components/figures/dither-field';
import HeroFloaters from '@/components/figures/hero-floaters';
import ProfileCard from '@/components/directory/profile-card';
import SearchBox from '@/components/directory/search-box';
import SectionHeading from '@/components/directory/section-heading';
import TagChips from '@/components/directory/tag-chips';
import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';
import SourceFigure from '@/components/figures/source-figure';
import type { ProfileCard as Card, TagChip } from '@/types';

type Props = {
    featured: Card[];
    trending: Card[];
    recent: Card[];
    tags: TagChip[];
    counts: { profiles: number; claimed: number; visitors: number };
};

const POINTS: { glyph: GlyphName; body: string }[] = [
    {
        glyph: 'feed',
        body: 'We read a channel’s public posts: projects, eras, habits and the posts worth starting with.',
    },
    {
        glyph: 'claim',
        body: 'Creators claim their page with a short code in the channel bio. No passwords, no access.',
    },
    {
        glyph: 'profile',
        body: 'You get who runs it, what they build and all their socials. Every line links to its post.',
    },
];

export default function Home({
    featured,
    trending,
    recent,
    tags,
    counts,
}: Props) {
    return (
        <>
            <Head title="Find out who's behind the channel">
                <meta
                    head-key="description"
                    name="description"
                    content="Search any Telegram creator: who runs the channel, what they build, their journey, and all their socials."
                />
            </Head>

            {/* Dithered band: starts behind the (transparent) header and
                melts into the canvas above the headline. */}
            <DitherField
                className="absolute inset-x-0 top-0 h-[320px] sm:h-[440px]"
                tone="mint"
                shape="simplex"
                secondaryShape="warp"
                fade="band"
                speed={0.2}
                size={2.5}
            />

            {/* Real builders drifting in the field (photos, not dithered). */}
            {/* Phones: the band between the header and the badge. */}
            <div className="absolute inset-x-0 top-16 z-10 h-[164px] sm:h-[376px]">
                <HeroFloaters cards={[...featured, ...trending, ...recent]} />
            </div>

            <div className="relative mx-auto w-full max-w-[1080px] px-5">
                <section className="max-w-2xl pt-40 pb-16 sm:pt-60 sm:pb-20">
                    <span className="mb-7 inline-flex items-center gap-2 rounded-full bg-card py-1 pr-3 pl-2 text-xs text-muted-foreground shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/80">
                        <span className="size-1.5 rounded-full bg-brand ring-2 ring-brand-soft" />
                        {counts.profiles > 0
                            ? `${counts.profiles.toLocaleString()} builders and counting`
                            : 'The Telegram builder directory'}
                        {counts.visitors > 0 && (
                            <>
                                <span
                                    aria-hidden="true"
                                    className="text-border"
                                >
                                    ·
                                </span>
                                <span className="tabular">
                                    {counts.visitors.toLocaleString()} visitors
                                </span>
                            </>
                        )}
                    </span>

                    <Display as="h1" size="xl" lead="Find out who's" stack>
                        behind the channel.
                    </Display>

                    <p className="mt-5 max-w-md text-base leading-relaxed text-pretty text-muted-foreground">
                        Search any Telegram creator and see who actually runs
                        it, what they build, their journey, and every social
                        link, all from what they already post.
                    </p>

                    <SearchBox className="mt-8 max-w-xl" />

                    <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                        <Link
                            href="/claim"
                            className="group inline-flex items-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <span className="rounded-full bg-brand px-4 py-2 text-sm font-medium text-brand-foreground transition-[filter] group-hover:brightness-[0.97]">
                                Claim your page
                            </span>
                            <span className="-ml-0.5 flex size-9 items-center justify-center rounded-full bg-brand text-brand-foreground transition-transform duration-200 ease-out group-hover:translate-x-0.5">
                                <ArrowRight className="size-4" />
                            </span>
                        </Link>
                        <a
                            href="#how"
                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            How it works
                            <ArrowDown className="size-3.5" />
                        </a>
                    </div>
                </section>

                <section
                    aria-label="What Buildr does"
                    className="grid gap-3 sm:grid-cols-3"
                >
                    {POINTS.map((point) => (
                        <div
                            key={point.glyph}
                            className="group surface-fade p-5 pb-8"
                        >
                            <Glyph name={point.glyph} className="-ml-1" />
                            <p className="mt-5 text-[15px] leading-relaxed text-pretty text-foreground/85">
                                {point.body}
                            </p>
                        </div>
                    ))}
                </section>

                <section id="how" className="scroll-mt-24 pt-24">
                    <Display size="md" lead="How a profile">
                        gets written.
                    </Display>
                    <div className="surface mt-6 overflow-hidden">
                        <div className="px-5 pt-5 sm:px-10 sm:pt-8">
                            <SourceFigure className="mx-auto max-w-[640px]" />
                        </div>
                        <ol className="mt-6 grid border-t border-border/70 sm:grid-cols-3">
                            {[
                                [
                                    'Read',
                                    'Public posts from t.me, nothing private.',
                                ],
                                [
                                    'Write',
                                    'A short profile: hook, journey, projects.',
                                ],
                                [
                                    'Cite',
                                    'Each line keeps a link to its source post.',
                                ],
                            ].map(([title, body], i) => (
                                <li
                                    key={title}
                                    className="flex gap-3 border-border/70 px-5 py-5 not-last:border-b sm:px-8 sm:not-last:border-r sm:not-last:border-b-0"
                                >
                                    <span className="tabular font-mono text-xs text-ink-soft">
                                        0{i + 1}
                                    </span>
                                    <div>
                                        <div className="text-sm font-medium">
                                            {title}
                                        </div>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {body}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                <div className="space-y-20 pt-24">
                    {featured.length > 0 && (
                        <section>
                            <SectionHeading
                                lead="Featured"
                                title="builders."
                                description="Hand-picked channels worth following."
                            />
                            <div className="-mx-5 flex snap-x snap-mandatory scroll-px-5 scrollbar-none gap-3 overflow-x-auto px-5 pt-1 pb-3">
                                {featured.map((card) => (
                                    <ProfileCard
                                        key={card.url}
                                        card={card}
                                        className="w-[280px] shrink-0 snap-start"
                                    />
                                ))}
                            </div>
                        </section>
                    )}

                    {trending.length > 0 && (
                        <section>
                            <SectionHeading
                                lead="Trending"
                                title="this week."
                                href="/discover?sort=trending"
                            />
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {trending.map((card) => (
                                    <ProfileCard key={card.url} card={card} />
                                ))}
                            </div>
                        </section>
                    )}

                    {tags.length > 0 && (
                        <section>
                            <SectionHeading lead="Browse" title="by topic." />
                            <TagChips tags={tags} />
                        </section>
                    )}

                    {recent.length > 0 && (
                        <section>
                            <SectionHeading
                                lead="Recently"
                                title="joined."
                                href="/discover?sort=newest&claimed=1"
                            />
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {recent.map((card) => (
                                    <ProfileCard key={card.url} card={card} />
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="group surface relative flex flex-col gap-6 overflow-hidden p-6 sm:flex-row sm:items-center sm:justify-between sm:p-10">
                        <DitherField
                            className="absolute inset-y-0 right-0 w-2/3 [mask-image:linear-gradient(to_left,#000_20%,transparent)]"
                            tone="mint"
                            shape="ripple"
                            secondaryShape="simplex"
                            fade="none"
                            speed={0.15}
                            size={2}
                        />
                        <div className="relative flex items-start gap-5">
                            <Glyph name="send" className="hidden sm:block" />
                            <div>
                                <Display size="md" lead="Run a channel?">
                                    Your page might already exist.
                                </Display>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Claim it in two minutes and make it yours.
                                </p>
                            </div>
                        </div>
                        <Link
                            href="/claim"
                            className="relative inline-flex h-11 shrink-0 items-center gap-2 self-start rounded-full bg-primary px-5 text-[15px] font-medium text-primary-foreground shadow-soft transition-[filter,transform] duration-150 hover:brightness-110 active:scale-[0.97] sm:self-auto"
                        >
                            Claim your page
                            <ArrowRight className="size-4" />
                        </Link>
                    </section>
                </div>
            </div>
        </>
    );
}
