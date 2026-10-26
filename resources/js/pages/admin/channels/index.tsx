import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import {
    Card,
    DataTable,
    FilterSelect,
    Pagination,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import { PipelineStage, useLiveReload } from '@/components/admin/pipeline';
import type { Pipeline } from '@/components/admin/pipeline';
import type { Paginated } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCount, formatDate } from '@/lib/format';

type Row = {
    id: number;
    username: string;
    title: string | null;
    subscribers: number | null;
    claim_status: string;
    visibility: string;
    availability: string;
    scrape_status: string;
    last_scraped_at: string | null;
    cost: number;
    pipeline: Pipeline | null;
};

type Props = {
    channels: Paginated<Row>;
    filters: {
        q?: string;
        claim?: string;
        visibility?: string;
        scrape?: string;
    };
    suggestions: { id: number; username: string; created_at: string }[];
};

export default function AdminChannels({
    channels,
    filters,
    suggestions,
}: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const page = usePage();

    // Stages update live while any channel on this page has work queued.
    useLiveReload(
        channels.data.some(
            (c) => (c.pipeline?.queued ?? 0) > 0 || c.pipeline?.tone === 'sky',
        ),
        ['channels'],
    );
    const results = (
        page.flash as
            | {
                  bulkResults?: {
                      added: string[];
                      skipped: { handle: string; reason: string }[];
                  };
              }
            | undefined
    )?.bulkResults;

    const filter = (key: string, value: string) =>
        router.get(
            '/admin/channels',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );

    return (
        <PageContainer
            title="Channels"
            description="Every channel Buildr reads, claimed or not."
            wide
            actions={
                <Button asChild>
                    <Link href="/admin/channels/new">
                        <Plus />
                        Add channels
                    </Link>
                </Button>
            }
        >
            {results && (
                <Card title="Bulk add results">
                    <p className="text-sm">
                        <span className="text-muted-foreground">Added: </span>
                        <span className="font-mono text-xs">
                            {results.added.map((u) => `@${u}`).join(', ') ||
                                'none'}
                        </span>
                    </p>
                    {results.skipped.length > 0 && (
                        <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
                            {results.skipped.map((s) => (
                                <li key={s.handle}>
                                    <span className="font-mono text-xs text-foreground">
                                        {s.handle}
                                    </span>
                                    : {s.reason}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {suggestions.length > 0 && (
                <Card title="Suggested by visitors">
                    <div className="flex flex-wrap gap-2">
                        {suggestions.map((s) => (
                            <span
                                key={s.id}
                                className="flex items-center gap-1 rounded-full bg-background py-1 pr-1 pl-3 text-sm ring-1 ring-border/80"
                            >
                                <a
                                    href={`https://t.me/${s.username}`}
                                    target="_blank"
                                    rel="noopener"
                                    className="mr-1 font-mono text-xs hover:underline"
                                >
                                    @{s.username}
                                </a>
                                <button
                                    type="button"
                                    className="rounded-full bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground transition-transform active:scale-95"
                                    onClick={() =>
                                        router.post('/admin/channels', {
                                            handles: s.username,
                                        })
                                    }
                                >
                                    Add
                                </button>
                                <button
                                    type="button"
                                    className="rounded-full px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                    onClick={() =>
                                        postAction(
                                            `/admin/suggestions/${s.id}/dismiss`,
                                        )
                                    }
                                >
                                    Dismiss
                                </button>
                            </span>
                        ))}
                    </div>
                </Card>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        filter('q', q);
                    }}
                    className="relative"
                >
                    <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-soft" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search username or title"
                        className="h-9 w-64 rounded-full pl-10"
                    />
                </form>
                {[
                    ['claim', ['', 'unclaimed', 'claimed']],
                    ['visibility', ['', 'hidden', 'published', 'removed']],
                    [
                        'scrape',
                        [
                            '',
                            'idle',
                            'queued',
                            'backfilling',
                            'incremental',
                            'failed',
                        ],
                    ],
                ].map(([key, options]) => (
                    <FilterSelect
                        key={key as string}
                        value={
                            (filters as Record<string, string | undefined>)[
                                key as string
                            ] ?? ''
                        }
                        onChange={(e) => filter(key as string, e.target.value)}
                    >
                        {(options as string[]).map((o) => (
                            <option key={o} value={o}>
                                {o === '' ? `Any ${key}` : o}
                            </option>
                        ))}
                    </FilterSelect>
                ))}
            </div>

            <DataTable
                columns={[
                    'Channel',
                    { label: 'Subscribers', className: 'text-right' },
                    'Claim',
                    'Visibility',
                    'Pipeline',
                    'Availability',
                    'Last scraped',
                    { label: 'AI cost', className: 'text-right' },
                ]}
                empty="No channels match these filters."
                emptyGlyph="search"
            >
                {channels.data.map((c) => (
                    <tr key={c.id}>
                        <td>
                            <Link
                                href={`/admin/channels/${c.id}`}
                                className="font-mono text-xs font-medium hover:underline"
                            >
                                @{c.username}
                            </Link>
                            <div className="max-w-56 truncate text-xs text-muted-foreground">
                                {c.title}
                            </div>
                        </td>
                        <td className="tabular text-right font-mono text-xs">
                            {formatCount(c.subscribers)}
                        </td>
                        <td>
                            <StatusBadge value={c.claim_status} />
                        </td>
                        <td>
                            <StatusBadge value={c.visibility} />
                        </td>
                        <td>
                            <PipelineStage pipeline={c.pipeline} />
                        </td>
                        <td>
                            <StatusBadge value={c.availability} />
                        </td>
                        <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                            {formatDate(c.last_scraped_at)}
                        </td>
                        <td className="tabular text-right font-mono text-xs">
                            ${c.cost.toFixed(2)}
                        </td>
                    </tr>
                ))}
            </DataTable>

            <Pagination page={channels} />
        </PageContainer>
    );
}

AdminChannels.layout = {
    breadcrumbs: [{ title: 'Channels', href: '/admin/channels' }],
};
