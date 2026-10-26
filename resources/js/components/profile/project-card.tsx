import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatMonth } from '@/lib/format';
import type { ProjectItem, ProjectStatus } from '@/types';

const STATUS: Record<
    ProjectStatus,
    {
        label: string;
        variant: 'mint' | 'sky' | 'violet' | 'rose' | 'outline';
    }
> = {
    building: { label: 'Building', variant: 'mint' },
    launched: { label: 'Launched', variant: 'sky' },
    idea: { label: 'Idea', variant: 'violet' },
    abandoned: { label: 'Paused', variant: 'rose' },
    unknown: { label: 'Quiet lately', variant: 'outline' },
};

export function ProjectStatusPill({ status }: { status: ProjectStatus }) {
    const s = STATUS[status] ?? STATUS.unknown;

    return (
        <Badge
            variant={s.variant}
            className={s.variant === 'outline' ? 'text-muted-foreground' : ''}
        >
            {s.label}
        </Badge>
    );
}

function VisitLink({ url }: { url: string }) {
    return (
        <a
            href={url}
            target="_blank"
            rel="nofollow noopener ugc"
            className="inline-flex items-center gap-0.5 text-sm text-sky hover:underline"
        >
            Visit
            <ArrowUpRight className="size-3.5" />
        </a>
    );
}

export default function ProjectCard({
    project,
    line,
    source,
}: {
    project: ProjectItem;
    line?: string | null;
    /** Which channel it comes from (on a profile that spans channels). */
    source?: { label: string; href: string } | null;
}) {
    return (
        <div className="surface flex h-full flex-col gap-2 p-4">
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-medium tracking-tight">{project.name}</h3>
                <ProjectStatusPill status={project.status} />
            </div>
            {(line ?? project.description) && (
                <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                    {line ?? project.description}
                </p>
            )}
            <ProjectHistory project={project} />
            <div className="mt-auto flex items-center justify-between gap-2 pt-2 text-xs text-muted-foreground">
                <span className="tabular min-w-0 truncate font-mono">
                    {project.mention_count} post
                    {project.mention_count === 1 ? '' : 's'}
                    {source && (
                        <>
                            {' · '}
                            <Link
                                href={source.href}
                                className="transition-colors hover:text-foreground"
                            >
                                {source.label}
                            </Link>
                        </>
                    )}
                </span>
                {project.url && <VisitLink url={project.url} />}
            </div>
        </div>
    );
}

/**
 * Renames, domain moves and pivots the resolver found, as one quiet line.
 */
function ProjectHistory({ project }: { project: ProjectItem }) {
    if (!project.history) {
        return null;
    }

    return (
        <p className="mt-1 flex gap-1.5 font-mono text-[11px] leading-relaxed text-ink-soft">
            <span aria-hidden="true">↳</span>
            <span className="text-pretty">{project.history}</span>
        </p>
    );
}

/**
 * Projects as a table in a grey tray: column labels sit on the tray, rows
 * on the white card. Collapses to stacked rows on phones.
 */
export function ProjectTable({ projects }: { projects: ProjectItem[] }) {
    return (
        <div className="rounded-[20px] bg-secondary p-1 ring-1 ring-border/60">
            <div className="hidden grid-cols-[minmax(0,1fr)_110px_80px_130px_64px] gap-4 px-4 pt-2 pb-2.5 text-xs text-muted-foreground sm:grid">
                <span>Project</span>
                <span>Status</span>
                <span className="text-right">Posts</span>
                <span>Active</span>
                <span />
            </div>
            <ul className="divide-y divide-border/70 overflow-hidden rounded-2xl bg-card shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                {projects.map((project) => (
                    <li
                        key={project.id}
                        className="grid gap-x-4 gap-y-1.5 px-4 py-3.5 transition-colors hover:bg-background/60 sm:grid-cols-[minmax(0,1fr)_110px_80px_130px_64px] sm:items-center"
                    >
                        <div className="min-w-0">
                            <div className="text-sm font-medium">
                                {project.name}
                            </div>
                            {project.description && (
                                <p className="mt-0.5 line-clamp-2 text-[13px] text-muted-foreground">
                                    {project.description}
                                </p>
                            )}
                            <ProjectHistory project={project} />
                        </div>
                        <div className="flex items-center gap-2 sm:block">
                            <ProjectStatusPill status={project.status} />
                            <span className="tabular font-mono text-xs text-muted-foreground sm:hidden">
                                {project.mention_count} posts
                            </span>
                        </div>
                        <span className="tabular hidden text-right font-mono text-sm sm:block">
                            {project.mention_count}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {formatMonth(project.first_seen)}
                            {project.last_seen &&
                            project.last_seen !== project.first_seen
                                ? ` – ${formatMonth(project.last_seen)}`
                                : ''}
                        </span>
                        <span className="sm:text-right">
                            {project.url ? (
                                <VisitLink url={project.url} />
                            ) : (
                                <span className="hidden text-ink-soft sm:inline">
                                    –
                                </span>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
