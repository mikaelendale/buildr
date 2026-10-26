import { Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Card, Counter, DataTable, postAction } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
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
    queues,
    failed,
    scrapingPaused,
    canary,
    failingChannels,
    horizon,
}: Props) {
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
