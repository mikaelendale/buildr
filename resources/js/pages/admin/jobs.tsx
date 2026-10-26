import { Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import AiRunList from '@/components/admin/ai-run-list';
import type { AiRunRow } from '@/components/admin/ai-run-list';
import { useLiveReload } from '@/components/admin/pipeline';
import { Card, Counter, DataTable, postAction } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type QueuedJob = {
    id: number;
    queue: string;
    name: string;
    channel_id: number | null;
    channel: string | null;
    step: string | null;
    running: boolean;
    attempts: number;
    available_at: number;
};

type Lane = {
    name: string;
    provider: string;
    models: Record<
        string,
        {
            model: string;
            tier: number;
            blocked_for: number | null;
            daily: boolean;
            /** Recent failure rate, 0 healthy to 1 failing. */
            health: number;
            today: number;
            rpd: number;
        }
    >;
};

const STEP_LABELS: Record<string, string> = {
    extract: 'labelling posts',
    projects: 'finding projects',
    stats: 'counting stats',
    summary_start: 'writing digests',
    summary_map: 'writing digests',
    summary_reduce: 'writing profile',
    verify: 'fact-checking',
    index: 'finishing',
    og: 'finishing',
    done: 'finishing',
};

function wait(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${Math.floor(seconds / 3600)}h ${Math.round((seconds % 3600) / 60)}m`;
}

function JobRow({ job }: { job: QueuedJob }) {
    const delay = job.available_at - Math.floor(Date.now() / 1000);

    return (
        <li className="flex flex-wrap items-center gap-x-2 gap-y-1 py-2 text-xs first:pt-0 last:pb-0">
            {job.running && (
                <span className="relative flex size-1.5 text-sky">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:animate-none" />
                    <span className="relative inline-flex size-1.5 rounded-full bg-current" />
                </span>
            )}
            <span className="font-mono font-medium">{job.name}</span>
            {job.step && (
                <Badge variant="sky">{STEP_LABELS[job.step] ?? job.step}</Badge>
            )}
            {job.channel && (
                <Link
                    href={`/admin/channels/${job.channel_id}`}
                    className="font-mono text-muted-foreground hover:text-foreground"
                >
                    @{job.channel}
                </Link>
            )}
            <span className="ml-auto font-mono whitespace-nowrap text-ink-soft">
                {job.queue}
                {job.attempts > 1 && ` · try ${job.attempts}`}
                {!job.running && delay > 5 && ` · in ${wait(delay)}`}
            </span>
        </li>
    );
}

type Props = {
    running: QueuedJob[];
    waiting: QueuedJob[];
    recentAi: AiRunRow[];
    lanes: Lane[];
    quota: {
        channels: {
            id: number;
            username: string | null;
            step: string;
            until: string;
        }[];
    } | null;
    queues: Record<string, number>;
    failed: {
        uuid: string;
        queue: string;
        name: string;
        error: string;
        failed_at: string;
    }[];
    scrapingPaused: boolean;
    canary: { ok: boolean; message: string; checked_at: string } | null;
    failingChannels: {
        id: number;
        username: string;
        last_error: string | null;
        failure_count: number;
    }[];
    horizon: boolean;
};

export default function AdminJobs({
    running,
    waiting,
    recentAi,
    lanes,
    quota,
    queues,
    failed,
    scrapingPaused,
    canary,
    failingChannels,
    horizon,
}: Props) {
    // Live while workers have anything to do.
    useLiveReload(running.length + waiting.length > 0, [
        'running',
        'waiting',
        'recentAi',
        'lanes',
        'quota',
        'queues',
        'failed',
    ]);

    return (
        <PageContainer
            title="Jobs"
            description="Queues, the scraper canary, and anything that failed."
            wide
            actions={
                <>
                    {horizon && (
                        <Button asChild variant="outline" size="sm">
                            <a href="/horizon" target="_blank" rel="noopener">
                                Horizon
                                <ArrowUpRight />
                            </a>
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant={scrapingPaused ? 'default' : 'destructive'}
                        onClick={() =>
                            postAction('/admin/jobs/scraping/toggle')
                        }
                    >
                        {scrapingPaused
                            ? 'Resume scraping'
                            : 'Pause all scraping'}
                    </Button>
                </>
            }
        >
            <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                {['scrape', 'ai', 'default', 'media'].map((q) => (
                    <Counter
                        key={q}
                        label={`${q} queue`}
                        value={
                            <span className="font-mono">{queues[q] ?? 0}</span>
                        }
                    />
                ))}
                <Counter
                    label="Failed jobs"
                    value={<span className="font-mono">{failed.length}</span>}
                    alert={failed.length > 0}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card
                    title="Workers"
                    actions={
                        running.length > 0 ? (
                            <Badge variant="sky">
                                {running.length} running
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="text-muted-foreground"
                            >
                                idle
                            </Badge>
                        )
                    }
                >
                    {running.length === 0 && waiting.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing running or queued. If you expected work
                            here, check that a queue worker is running.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {[...running, ...waiting].map((job) => (
                                <JobRow key={job.id} job={job} />
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="AI routes">
                    <div className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            Each call goes to the best free model for the step:
                            T1 strong (writer, fact-check, projects), T3 light
                            (labelling, digests), T2 either.
                        </p>
                        {lanes.map((lane) => (
                            <div key={lane.name}>
                                <div className="mb-1.5 font-mono text-xs font-medium">
                                    {lane.name}
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {Object.values(lane.models).map((m) => (
                                        <Badge
                                            key={m.model}
                                            variant={
                                                m.blocked_for === null
                                                    ? 'mint'
                                                    : m.daily
                                                      ? 'rose'
                                                      : 'amber'
                                            }
                                            className="font-mono"
                                        >
                                            T{m.tier} {m.model}
                                            {m.today > 0 &&
                                                ` · ${m.today}${m.rpd > 0 ? `/${m.rpd}` : ''} today`}
                                            {m.blocked_for === null &&
                                                m.health >= 0.3 &&
                                                ' · flaky'}
                                            {m.blocked_for !== null &&
                                                (m.daily
                                                    ? ` · out for ${wait(m.blocked_for)}`
                                                    : ` · cooling ${wait(m.blocked_for)}`)}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        ))}
                        {quota && quota.channels.length > 0 && (
                            <div className="border-t border-border/70 pt-3 text-xs">
                                <div className="mb-1.5 text-muted-foreground">
                                    Waiting for quota
                                </div>
                                {quota.channels.map((c) => (
                                    <div
                                        key={c.id}
                                        className="flex justify-between gap-2 font-mono"
                                    >
                                        <Link
                                            href={`/admin/channels/${c.id}`}
                                            className="hover:underline"
                                        >
                                            @{c.username}
                                        </Link>
                                        <span className="text-ink-soft">
                                            {STEP_LABELS[c.step] ?? c.step} ·{' '}
                                            {new Date(
                                                c.until,
                                            ).toLocaleTimeString()}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </Card>
            </div>

            <Card title="AI calls">
                <AiRunList
                    runs={recentAi}
                    empty="No AI calls yet."
                    showChannel
                />
            </Card>

            <Card
                title="Scraper"
                actions={
                    scrapingPaused ? (
                        <Badge variant="amber">paused</Badge>
                    ) : (
                        <Badge variant="mint">running</Badge>
                    )
                }
            >
                {canary ? (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span
                            className={
                                canary.ok
                                    ? 'size-2 rounded-full bg-mint ring-4 ring-mint-soft'
                                    : 'size-2 rounded-full bg-rose ring-4 ring-rose-soft'
                            }
                        />
                        <span className="font-medium">
                            Canary {canary.ok ? 'OK' : 'failed'}
                        </span>
                        <span className="text-muted-foreground">
                            {canary.message}
                        </span>
                        <span className="tabular ml-auto font-mono text-xs text-muted-foreground">
                            {new Date(canary.checked_at).toLocaleString()}
                        </span>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No canary run yet.
                    </p>
                )}
                {failingChannels.length > 0 && (
                    <div className="mt-4 space-y-2">
                        <h3 className="eyebrow">Failing channels</h3>
                        <ul className="divide-y divide-border/70 rounded-xl bg-rose-soft/60 px-3 ring-1 ring-rose/10">
                            {failingChannels.map((c) => (
                                <li
                                    key={c.id}
                                    className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-2 text-sm"
                                >
                                    <Link
                                        href={`/admin/channels/${c.id}`}
                                        className="font-mono text-xs font-medium hover:underline"
                                    >
                                        @{c.username}
                                    </Link>
                                    <span className="tabular font-mono text-[11px] text-rose">
                                        {c.failure_count} failures
                                    </span>
                                    <span className="w-full truncate text-xs text-muted-foreground sm:w-auto sm:flex-1">
                                        {c.last_error}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Card>

            <div className="space-y-2">
                <h2 className="eyebrow">Failed jobs</h2>
                <DataTable
                    columns={['Job', 'Queue', 'Failed at', '']}
                    empty="No failed jobs. Everything ran."
                    emptyGlyph="clock"
                >
                    {failed.map((job) => (
                        <tr key={job.uuid} className="align-top">
                            <td className="max-w-xl">
                                <div className="font-mono text-xs font-medium">
                                    {job.name}
                                </div>
                                <p className="mt-1 line-clamp-2 font-mono text-[11px] text-rose">
                                    {job.error}
                                </p>
                            </td>
                            <td>
                                <Badge
                                    variant="outline"
                                    className="font-mono text-muted-foreground"
                                >
                                    {job.queue}
                                </Badge>
                            </td>
                            <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                                {job.failed_at}
                            </td>
                            <td className="text-right whitespace-nowrap">
                                <div className="flex justify-end gap-1">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            postAction(
                                                `/admin/jobs/${job.uuid}/retry`,
                                            )
                                        }
                                    >
                                        Retry
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/admin/jobs/${job.uuid}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    ))}
                </DataTable>
            </div>
        </PageContainer>
    );
}

AdminJobs.layout = { breadcrumbs: [{ title: 'Jobs', href: '/admin/jobs' }] };
