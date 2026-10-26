import { Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Card,
    EmptyState,
    FilterSelect,
    NoteAction,
    Pagination,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import { PipelineStage, useLiveReload } from '@/components/admin/pipeline';
import type { Pipeline } from '@/components/admin/pipeline';
import type { Paginated } from '@/components/admin/ui';
import AiRunList from '@/components/admin/ai-run-list';
import type { AiRunRow } from '@/components/admin/ai-run-list';
import VerificationReport from '@/components/admin/verification-report';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Segmented from '@/components/ui/segmented';
import { formatCount, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type SummaryVersion = {
    id: number;
    version: number;
    status: string;
    mode: string;
    triggered_by: string;
    model: string | null;
    created_at: string | null;
    content: Record<string, unknown> | null;
    verification: never;
};

type Props = {
    channel: {
        id: number;
        username: string;
        title: string | null;
        description: string | null;
        subscribers: number | null;
        timezone: string | null;
        failure_count: number;
        last_error: string | null;
        backfill_complete: boolean;
        claim_status: string;
        visibility: string;
        availability: string;
        scrape_status: string;
        review_status: string | null;
        creator: { id: number; slug: string; display_name: string } | null;
        last_scraped_at: string | null;
        last_processed_at: string | null;
        stats: Record<string, unknown> | null;
        post_count: number;
        cost: number;
    };
    summaries: SummaryVersion[];
    pipeline: Pipeline;
    aiRuns: AiRunRow[];
    posts: Paginated<{
        msg_id: number;
        posted_at: string | null;
        text: string | null;
        views: number | null;
        lang: string | null;
        is_forward: boolean;
        is_ad: boolean;
        is_deleted: boolean;
        extraction: {
            type: string;
            topics: string[];
            projects: { name: string; role: string }[];
            tools: string[];
            milestone: string | null;
            tone: string | null;
            is_personal_sensitive: boolean;
        } | null;
    }>;
};

function Meta({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="tabular mt-0.5 truncate font-mono text-sm">
                {children}
            </div>
        </div>
    );
}

export default function AdminChannel({
    channel,
    summaries,
    posts,
    pipeline,
    aiRuns,
}: Props) {
    // Live while the channel has work queued or running.
    useLiveReload(pipeline.queued > 0 || pipeline.tone === 'sky', [
        'pipeline',
        'aiRuns',
        'channel',
        'summaries',
    ]);

    const [left, setLeft] = useState(summaries[1]?.id ?? summaries[0]?.id);
    const [right, setRight] = useState(summaries[0]?.id);
    const [tab, setTab] = useState<'posts' | 'history' | 'stats'>('history');

    const diff = useMemo(() => {
        const a = summaries.find((s) => s.id === left)?.content ?? {};
        const b = summaries.find((s) => s.id === right)?.content ?? {};
        const keys = Array.from(
            new Set([...Object.keys(a), ...Object.keys(b)]),
        );

        return keys.map((k) => ({
            key: k,
            a: JSON.stringify(a[k] ?? null, null, 1),
            b: JSON.stringify(b[k] ?? null, null, 1),
        }));
    }, [left, right, summaries]);

    return (
        <PageContainer
            title={`@${channel.username}`}
            description={channel.title ?? undefined}
            wide
            actions={
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            postAction(`/admin/channels/${channel.id}/rescrape`)
                        }
                    >
                        Rescrape
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            postAction(
                                `/admin/channels/${channel.id}/reprocess`,
                            )
                        }
                    >
                        Reprocess
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            postAction(
                                `/admin/review/channel/${channel.id}/regenerate`,
                            )
                        }
                    >
                        Regenerate summary
                    </Button>
                    {channel.claim_status === 'unclaimed' &&
                        channel.visibility !== 'removed' &&
                        (channel.visibility === 'published' ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.put(
                                        `/admin/channels/${channel.id}`,
                                        { visibility: 'hidden' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Hide
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.put(
                                        `/admin/channels/${channel.id}`,
                                        { visibility: 'published' },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Publish
                            </Button>
                        ))}
                    {channel.claim_status === 'claimed' && (
                        <NoteAction
                            label="Release from owner"
                            title={`Release @${channel.username}`}
                            url={`/admin/channels/${channel.id}/release`}
                            field="reason"
                            required={false}
                            description="Detaches it from the creator. Posts stay; the page is rewritten as an unclaimed channel and waits for review."
                        />
                    )}
                    <NoteAction
                        label="Remove + blocklist"
                        title={`Remove @${channel.username}`}
                        url={`/admin/channels/${channel.id}`}
                        field="reason"
                        variant="destructive"
                        description="Deletes posts and summaries and blocklists the username."
                        extra={{ _method: 'delete' }}
                    />
                </>
            }
        >
            <Card title="Pipeline">
                <div className="grid gap-5 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                    <div className="space-y-2">
                        <PipelineStage pipeline={pipeline} />
                        <p className="text-xs text-muted-foreground">
                            {pipeline.posts} posts scraped
                            {pipeline.queued > 0 &&
                                ` · ${pipeline.queued} job${pipeline.queued === 1 ? '' : 's'} queued`}
                            {pipeline.attempts > 1 &&
                                ` · attempt ${pipeline.attempts}`}
                        </p>
                    </div>
                    <AiRunList runs={aiRuns} empty="No AI calls yet." />
                </div>
            </Card>

            <div className="flex flex-wrap items-center gap-1.5">
                <StatusBadge value={channel.claim_status} />
                <StatusBadge value={channel.visibility} />
                <StatusBadge value={channel.availability} />
                <StatusBadge value={channel.scrape_status} />
                {channel.review_status && (
                    <StatusBadge value={channel.review_status} />
                )}
                <span className="mx-1 h-4 w-px bg-border" />
                <a
                    href={`https://t.me/s/${channel.username}`}
                    target="_blank"
                    rel="noopener"
                    className="inline-flex items-center gap-0.5 text-sm text-sky hover:underline"
                >
                    t.me
                    <ArrowUpRight className="size-3.5" />
                </a>
                {channel.creator && (
                    <Link
                        href={`/admin/creators/${channel.creator.id}`}
                        className="text-sm text-sky hover:underline"
                    >
                        Creator: {channel.creator.display_name}
                    </Link>
                )}
            </div>

            <div className="surface grid grid-cols-2 gap-x-6 gap-y-4 p-5 sm:grid-cols-3 lg:grid-cols-6">
                <Meta label="Subscribers">
                    {formatCount(channel.subscribers)}
                </Meta>
                <Meta label="Posts">{channel.post_count}</Meta>
                <Meta label="AI cost">${channel.cost.toFixed(2)}</Meta>
                <Meta label="Last scraped">
                    {formatDate(channel.last_scraped_at) || '—'}
                </Meta>
                <Meta label="Last processed">
                    {formatDate(channel.last_processed_at) || '—'}
                </Meta>
                <div className="min-w-0">
                    <label
                        htmlFor="tz"
                        className="text-xs text-muted-foreground"
                    >
                        Timezone
                    </label>
                    <input
                        id="tz"
                        defaultValue={channel.timezone ?? ''}
                        placeholder="Africa/Addis_Ababa"
                        onBlur={(e) =>
                            e.target.value !== (channel.timezone ?? '') &&
                            router.put(
                                `/admin/channels/${channel.id}`,
                                { timezone: e.target.value || null },
                                { preserveScroll: true },
                            )
                        }
                        className="mt-0.5 h-7 w-full rounded-lg bg-secondary px-2 font-mono text-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>
            </div>

            {channel.last_error && (
                <div className="rounded-2xl bg-rose-soft p-4 text-sm text-rose ring-1 ring-rose/15">
                    <div className="font-medium">
                        Last error · {channel.failure_count} in a row
                    </div>
                    <p className="mt-1 font-mono text-xs break-words">
                        {channel.last_error}
                    </p>
                </div>
            )}

            <Segmented
                label="Channel detail"
                value={tab}
                onChange={(v) => setTab(v as typeof tab)}
                items={[
                    {
                        value: 'history',
                        label: 'Summary versions',
                        count: summaries.length,
                    },
                    { value: 'posts', label: 'Posts', count: posts.total },
                    { value: 'stats', label: 'Stats' },
                ]}
            />

            {tab === 'history' && (
                <div className="space-y-4">
                    {summaries.length === 0 && (
                        <div className="surface">
                            <EmptyState glyph="quote">
                                No summaries yet. Reprocess the channel to write
                                one.
                            </EmptyState>
                        </div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {summaries.map((s) => (
                            <div key={s.id} className="surface p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-mono text-sm font-medium">
                                        v{s.version}
                                    </span>
                                    <StatusBadge value={s.status} />
                                </div>
                                <p className="mt-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                                    {s.mode} · {s.triggered_by}
                                    <br />
                                    {s.model} · {formatDate(s.created_at)}
                                </p>
                                {(s.status === 'failed_verification' ||
                                    s.status === 'verified') && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                        onClick={() =>
                                            postAction(
                                                `/admin/summaries/${s.id}/approve`,
                                            )
                                        }
                                    >
                                        Approve
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>

                    {summaries.length > 1 && (
                        <Card
                            title="Compare versions"
                            actions={
                                <div className="flex items-center gap-2 text-sm">
                                    <FilterSelect
                                        value={left}
                                        onChange={(e) =>
                                            setLeft(Number(e.target.value))
                                        }
                                        className="h-8 font-mono"
                                    >
                                        {summaries.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                v{s.version}
                                            </option>
                                        ))}
                                    </FilterSelect>
                                    <span className="text-muted-foreground">
                                        vs
                                    </span>
                                    <FilterSelect
                                        value={right}
                                        onChange={(e) =>
                                            setRight(Number(e.target.value))
                                        }
                                        className="h-8 font-mono"
                                    >
                                        {summaries.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                v{s.version}
                                            </option>
                                        ))}
                                    </FilterSelect>
                                </div>
                            }
                        >
                            <div className="space-y-1.5">
                                {diff.map((d) => (
                                    <div
                                        key={d.key}
                                        className={cn(
                                            'grid gap-3 rounded-xl p-3 font-mono text-[11px] leading-relaxed sm:grid-cols-[140px_1fr_1fr]',
                                            d.a !== d.b
                                                ? 'bg-amber-soft ring-1 ring-amber/15'
                                                : 'bg-secondary/60',
                                        )}
                                    >
                                        <span className="font-medium">
                                            {d.key}
                                        </span>
                                        <pre className="overflow-x-auto whitespace-pre-wrap [overflow-wrap:anywhere]">
                                            {d.a}
                                        </pre>
                                        <pre className="overflow-x-auto whitespace-pre-wrap [overflow-wrap:anywhere]">
                                            {d.b}
                                        </pre>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    )}

                    {summaries[0] && (
                        <Card title={`Verification (v${summaries[0].version})`}>
                            <VerificationReport
                                report={summaries[0].verification}
                            />
                        </Card>
                    )}
                </div>
            )}

            {tab === 'posts' && (
                <div className="space-y-2">
                    {posts.data.length === 0 && (
                        <div className="surface">
                            <EmptyState glyph="feed">
                                No posts scraped yet.
                            </EmptyState>
                        </div>
                    )}
                    {posts.data.map((p) => (
                        <article
                            key={p.msg_id}
                            className={cn(
                                'surface p-4 text-sm',
                                p.is_deleted && 'opacity-50',
                            )}
                        >
                            <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                <a
                                    href={`https://t.me/${channel.username}/${p.msg_id}`}
                                    target="_blank"
                                    rel="noopener"
                                    className="mr-1 font-mono font-medium text-foreground hover:underline"
                                >
                                    #{p.msg_id}
                                </a>
                                <span className="tabular font-mono">
                                    {formatDate(p.posted_at)} ·{' '}
                                    {formatCount(p.views)} views
                                </span>
                                {p.lang && (
                                    <Badge
                                        variant="outline"
                                        className="font-mono text-muted-foreground"
                                    >
                                        {p.lang}
                                    </Badge>
                                )}
                                {p.is_forward && (
                                    <Badge
                                        variant="outline"
                                        className="text-muted-foreground"
                                    >
                                        forward
                                    </Badge>
                                )}
                                {p.is_ad && <Badge variant="amber">ad</Badge>}
                                {p.is_deleted && (
                                    <Badge
                                        variant="outline"
                                        className="text-muted-foreground"
                                    >
                                        deleted
                                    </Badge>
                                )}
                                {p.extraction && (
                                    <Badge variant="sky">
                                        {p.extraction.type.replace(/_/g, ' ')}
                                    </Badge>
                                )}
                                {p.extraction?.is_personal_sensitive && (
                                    <Badge variant="rose">sensitive</Badge>
                                )}
                            </div>
                            <p className="mt-2 line-clamp-4 leading-relaxed whitespace-pre-line text-foreground/85">
                                {p.text}
                            </p>
                            {p.extraction && (
                                <p className="mt-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                                    {[
                                        ...p.extraction.topics,
                                        ...p.extraction.tools,
                                    ].join(' · ')}
                                    {p.extraction.projects.length > 0 &&
                                        ` · projects: ${p.extraction.projects.map((pr) => `${pr.name} (${pr.role})`).join(', ')}`}
                                    {p.extraction.milestone &&
                                        ` · milestone: ${p.extraction.milestone}`}
                                </p>
                            )}
                        </article>
                    ))}
                    <Pagination page={posts} />
                </div>
            )}

            {tab === 'stats' && (
                <Card>
                    {channel.stats ? (
                        <pre className="overflow-x-auto font-mono text-xs leading-relaxed">
                            {JSON.stringify(channel.stats, null, 2)}
                        </pre>
                    ) : (
                        <EmptyState glyph="chart">
                            Stats appear after the first processing run.
                        </EmptyState>
                    )}
                </Card>
            )}
        </PageContainer>
    );
}
