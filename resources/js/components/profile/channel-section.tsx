import { ArrowUpRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import Display from '@/components/brand/display';
import Glyph from '@/components/figures/glyph';
import ProjectCard, { ProjectTable } from '@/components/profile/project-card';
import StatCards from '@/components/profile/stat-cards';
import Segmented from '@/components/ui/segmented';
import { formatCount, formatDate, formatMonthRange } from '@/lib/format';
import type { ChannelSection as Section } from '@/types';

function Block({
    lead,
    title,
    children,
    id,
}: {
    lead: string;
    title: string;
    children: React.ReactNode;
    id: string;
}) {
    return (
        <section id={id} className="scroll-mt-36">
            <Display size="sm" lead={lead} className="mb-4">
                {title}
            </Display>
            {children}
        </section>
    );
}

/** "Read the posts" chips: every AI-written line keeps a path to its source. */
function Sources({ sources }: { sources: { msg_id: number; url: string }[] }) {
    if (sources.length === 0) {
        return null;
    }

    return (
        <span className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            <span>Sources</span>
            {sources.slice(0, 4).map((s) => (
                <a
                    key={s.msg_id}
                    href={s.url}
                    target="_blank"
                    rel="noopener"
                    className="tabular rounded-md bg-secondary px-1.5 py-0.5 font-mono text-[11px] text-foreground/70 transition-colors hover:bg-brand-soft hover:text-brand-ink"
                >
                    #{s.msg_id}
                </a>
            ))}
        </span>
    );
}

function useActiveSection(ids: string[]): string | undefined {
    const [active, setActive] = useState<string | undefined>(ids[0]);
    const key = ids.join(',');

    useEffect(() => {
        const elements = key
            .split(',')
            .map((id) => document.getElementById(id))
            .filter((el): el is HTMLElement => el !== null);

        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries
                    .filter((e) => e.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];

                if (visible) {
                    setActive(visible.target.id);
                }
            },
            { rootMargin: '-140px 0px -55% 0px' },
        );

        elements.forEach((el) => observer.observe(el));

        return () => observer.disconnect();
    }, [key]);

    return active;
}

/**
 * Sections 4–9 + 11 of a profile (plan §9.4), rendered once per channel.
 */
export default function ChannelSection({
    section,
    showHowTheyWork = true,
    withNav = false,
    actions,
}: {
    section: Section;
    showHowTheyWork?: boolean;
    withNav?: boolean;
    actions?: React.ReactNode;
}) {
    const building = section.building_now;
    const others = section.projects.filter(
        (p) => !building.some((b) => b.project.id === p.id),
    );
    const how = showHowTheyWork ? section.how_they_work : null;

    const nav = [
        { value: 'overview', label: 'Overview', show: true },
        { value: 'journey', label: 'Journey', show: section.eras.length > 0 },
        {
            value: 'projects',
            label: 'Projects',
            show: building.length + others.length > 0,
        },
        {
            value: 'start-here',
            label: 'Start here',
            show: section.start_here.length > 0,
        },
        {
            value: 'words',
            label: 'Their words',
            show: section.in_their_words.length > 0,
        },
        {
            value: 'latest',
            label: 'Latest posts',
            show: (section.recent_posts ?? []).length > 0,
        },
    ].filter((n) => n.show);

    const active = useActiveSection(withNav ? nav.map((n) => n.value) : []);

    return (
        <div className="space-y-14">
            {withNav && (
                <div className="sticky top-16 z-30 -mx-5 flex items-center justify-between gap-3 bg-background/85 px-5 py-2.5 backdrop-blur-md">
                    <Segmented
                        label="Profile sections"
                        value={active ?? 'overview'}
                        items={nav.map((n) => ({
                            value: n.value,
                            label: n.label,
                            href: `#${n.value}`,
                            anchor: true,
                        }))}
                    />
                    {actions && <div className="shrink-0">{actions}</div>}
                </div>
            )}

            <section id="overview" className="scroll-mt-36 space-y-6">
                {section.intro && (
                    <p className="max-w-2xl text-[17px] leading-relaxed text-pretty text-foreground/85">
                        {section.intro}
                    </p>
                )}

                {section.stats && <StatCards stats={section.stats} />}

                {section.basic && (
                    <div className="group flex items-start gap-4 rounded-2xl border border-dashed border-border bg-card/50 p-5">
                        <Glyph name="feed" className="size-10" />
                        <div>
                            <p className="text-sm font-medium">
                                The full profile is on its way.
                            </p>
                            <p className="mt-0.5 text-sm text-pretty text-muted-foreground">
                                It&apos;s written from the channel&apos;s posts
                                once there are enough of them. Until then, here
                                is what we know for sure and the latest posts.
                            </p>
                        </div>
                    </div>
                )}

                {how && (
                    <div className="surface p-5">
                        <div className="eyebrow">How they work</div>
                        <p className="mt-2 text-sm leading-relaxed text-pretty text-foreground/85">
                            {how.text}
                        </p>
                        {how.tools.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {how.tools.map((tool) => (
                                    <span
                                        key={tool}
                                        className="rounded-full bg-secondary px-2.5 py-0.5 font-mono text-[11px] text-foreground/75"
                                    >
                                        {tool}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {section.fun_facts.length > 0 && (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {section.fun_facts.map((fact) => (
                            <li
                                key={fact}
                                className="group surface flex items-start gap-3 px-4 py-3 text-sm text-pretty"
                            >
                                <Glyph
                                    name="spark"
                                    className="-mt-0.5 size-6 text-brand-ink"
                                />
                                {fact}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {section.eras.length > 0 && (
                <Block id="journey" lead="The" title="journey.">
                    <ol className="surface divide-y divide-border/70">
                        {section.eras.map((era, i) => (
                            <li
                                key={`${era.name}-${era.from}`}
                                className="grid gap-x-6 gap-y-1.5 p-5 sm:grid-cols-[176px_minmax(0,1fr)]"
                            >
                                <div className="flex items-center gap-2.5 sm:h-6">
                                    <span
                                        className={
                                            i === section.eras.length - 1
                                                ? 'size-2 shrink-0 rounded-full bg-brand ring-4 ring-brand-soft'
                                                : 'size-2 shrink-0 rounded-full bg-border ring-4 ring-secondary'
                                        }
                                    />
                                    <span className="font-mono text-xs whitespace-nowrap text-muted-foreground">
                                        {formatMonthRange(era.from, era.to)}
                                    </span>
                                </div>
                                <div>
                                    <h3 className="font-medium tracking-tight">
                                        {era.name}
                                    </h3>
                                    <p className="mt-1 text-sm leading-relaxed text-pretty text-foreground/80">
                                        {era.story}
                                    </p>
                                    <Sources sources={era.sources} />
                                </div>
                            </li>
                        ))}
                    </ol>
                </Block>
            )}

            {building.length + others.length > 0 && (
                <Block
                    id="projects"
                    lead={building.length > 0 ? 'Building' : 'Their'}
                    title={building.length > 0 ? 'now.' : 'projects.'}
                >
                    {building.length > 0 && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {building.map((item) => (
                                <div
                                    key={item.project.id}
                                    className="flex flex-col"
                                >
                                    <ProjectCard
                                        project={item.project}
                                        line={item.line}
                                    />
                                    <Sources sources={item.sources} />
                                </div>
                            ))}
                        </div>
                    )}
                    {others.length > 0 && (
                        <div className={building.length > 0 ? 'mt-6' : ''}>
                            {building.length > 0 && (
                                <div className="eyebrow mb-2">All projects</div>
                            )}
                            <ProjectTable projects={others} />
                        </div>
                    )}
                </Block>
            )}

            {section.start_here.length > 0 && (
                <Block id="start-here" lead="Start" title="here.">
                    <ol className="surface divide-y divide-border/70">
                        {section.start_here.map((post, i) => (
                            <li key={post.msg_id}>
                                <a
                                    href={post.url}
                                    target="_blank"
                                    rel="noopener"
                                    className="group flex gap-4 p-4 transition-colors hover:bg-background/60 sm:p-5"
                                >
                                    <span className="tabular pt-0.5 font-mono text-xs text-ink-soft">
                                        0{i + 1}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium tracking-tight">
                                            {post.why}
                                        </p>
                                        {post.snippet && (
                                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                                {post.snippet}
                                            </p>
                                        )}
                                        <div className="tabular mt-2 flex items-center gap-3 font-mono text-[11px] text-muted-foreground">
                                            {post.date && (
                                                <span>
                                                    {formatDate(post.date)}
                                                </span>
                                            )}
                                            {post.views !== null && (
                                                <span>
                                                    {formatCount(post.views)}{' '}
                                                    views
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <ArrowUpRight className="size-4 shrink-0 text-ink-soft transition-[color,transform] duration-150 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-foreground" />
                                </a>
                            </li>
                        ))}
                    </ol>
                </Block>
            )}

            {section.in_their_words.length > 0 && (
                <Block id="words" lead="In their" title="own words.">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {section.in_their_words.map((q) => (
                            <figure
                                key={q.msg_id}
                                className="surface-fade flex flex-col p-5"
                            >
                                <span
                                    aria-hidden="true"
                                    className="font-serif text-3xl leading-none text-brand-ink"
                                >
                                    &ldquo;
                                </span>
                                <blockquote className="mt-1 text-[17px] leading-snug font-light text-pretty">
                                    {q.quote}
                                </blockquote>
                                <figcaption className="mt-4 font-mono text-[11px] text-muted-foreground">
                                    <a
                                        href={q.url}
                                        target="_blank"
                                        rel="noopener"
                                        className="hover:text-foreground hover:underline"
                                    >
                                        {q.date
                                            ? formatDate(q.date)
                                            : `Post #${q.msg_id}`}{' '}
                                        ↗
                                    </a>
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                </Block>
            )}

            {(section.recent_posts ?? []).length > 0 && (
                <Block id="latest" lead="Fresh from" title="the channel.">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(section.recent_posts ?? []).map((post) => (
                            <a
                                key={post.msg_id}
                                href={post.url}
                                target="_blank"
                                rel="noopener"
                                className="group surface flex flex-col p-4 transition-[box-shadow,transform] duration-200 hover:-translate-y-0.5 hover:shadow-lift"
                            >
                                <p className="line-clamp-4 text-sm leading-relaxed text-pretty whitespace-pre-line text-foreground/85">
                                    {post.snippet}
                                </p>
                                <div className="tabular mt-auto flex items-center justify-between gap-3 pt-3 font-mono text-[11px] text-muted-foreground">
                                    <span>
                                        {post.date
                                            ? formatDate(post.date)
                                            : `#${post.msg_id}`}
                                        {post.views !== null &&
                                            ` · ${formatCount(post.views)} views`}
                                    </span>
                                    <ArrowUpRight className="size-3.5 text-ink-soft transition-colors group-hover:text-foreground" />
                                </div>
                            </a>
                        ))}
                    </div>
                </Block>
            )}
        </div>
    );
}
