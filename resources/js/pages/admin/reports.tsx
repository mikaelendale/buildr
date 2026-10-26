import { Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import {
    EmptyState,
    NoteAction,
    Pagination,
    StatusBadge,
} from '@/components/admin/ui';
import type { Paginated } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import Segmented from '@/components/ui/segmented';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    reason: string;
    reason_label: string;
    details: string | null;
    status: string;
    contact: string | null;
    reporter: {
        id: number;
        name: string;
        telegram_username: string | null;
    } | null;
    resolution_note: string | null;
    created_at: string | null;
    target: {
        type: string;
        id: number;
        name: string;
        url: string;
        admin_url: string;
    } | null;
};

export default function AdminReports({
    reports,
    status,
}: {
    reports: Paginated<Row>;
    status: string;
}) {
    return (
        <PageContainer
            title="Reports"
            description="Respond within 72 hours. A resolution note is required."
            wide
        >
            <Segmented
                label="Report status"
                value={status}
                onChange={(s) =>
                    router.get(
                        '/admin/reports',
                        { status: s },
                        { preserveState: true },
                    )
                }
                items={['open', 'resolved', 'dismissed', 'all'].map((s) => ({
                    value: s,
                    label: s.charAt(0).toUpperCase() + s.slice(1),
                }))}
            />

            {reports.data.length === 0 && (
                <div className="surface">
                    <EmptyState glyph="shield">
                        No {status === 'all' ? '' : `${status} `}reports here.
                    </EmptyState>
                </div>
            )}

            <div className="space-y-3">
                {reports.data.map((r) => (
                    <article key={r.id} className="surface p-5">
                        <div className="flex flex-wrap items-start gap-4">
                            <div className="min-w-0 flex-1 space-y-2 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge value={r.status} />
                                    <span className="font-medium">
                                        {r.reason_label}
                                    </span>
                                    <span className="tabular font-mono text-xs text-muted-foreground">
                                        #{r.id} · {formatDate(r.created_at)}
                                    </span>
                                </div>
                                {r.target && (
                                    <p className="flex flex-wrap items-center gap-x-2">
                                        <span className="text-muted-foreground">
                                            On
                                        </span>
                                        <Link
                                            href={r.target.admin_url}
                                            className="font-medium hover:underline"
                                        >
                                            {r.target.name}
                                        </Link>
                                        <a
                                            href={r.target.url}
                                            target="_blank"
                                            rel="noopener"
                                            className="inline-flex items-center gap-0.5 text-xs text-sky hover:underline"
                                        >
                                            public page
                                            <ArrowUpRight className="size-3" />
                                        </a>
                                    </p>
                                )}
                                {r.details && (
                                    <p className="rounded-xl bg-secondary/70 px-3 py-2 whitespace-pre-line text-foreground/80">
                                        {r.details}
                                    </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    From{' '}
                                    {r.reporter ? (
                                        <>
                                            {r.reporter.name}{' '}
                                            <span className="font-mono">
                                                @
                                                {r.reporter.telegram_username ??
                                                    '—'}
                                            </span>
                                        </>
                                    ) : (
                                        'a visitor'
                                    )}
                                    {r.contact ? (
                                        <>
                                            {' · contact: '}
                                            <span className="font-mono">
                                                {r.contact}
                                            </span>
                                        </>
                                    ) : null}
                                </p>
                                {r.resolution_note && (
                                    <p className="rounded-xl bg-mint-soft px-3 py-2 text-xs text-mint ring-1 ring-mint/15">
                                        Resolution: {r.resolution_note}
                                    </p>
                                )}
                            </div>

                            {r.status === 'open' && (
                                <div className="flex flex-wrap gap-1.5">
                                    <NoteAction
                                        label="Dismiss"
                                        title="Dismiss report"
                                        url={`/admin/reports/${r.id}/resolve`}
                                        extra={{ action: 'dismiss' }}
                                    />
                                    <NoteAction
                                        label="Resolved (edited)"
                                        title="Mark resolved"
                                        url={`/admin/reports/${r.id}/resolve`}
                                        extra={{ action: 'resolve' }}
                                        description="Use this after editing or hiding sections from the review or channel page."
                                    />
                                    <NoteAction
                                        label="Unpublish"
                                        title="Unpublish the target"
                                        url={`/admin/reports/${r.id}/resolve`}
                                        extra={{ action: 'unpublish' }}
                                    />
                                    {r.target?.type === 'channel' && (
                                        <NoteAction
                                            label="Remove + blocklist"
                                            title="Remove the channel"
                                            url={`/admin/reports/${r.id}/resolve`}
                                            extra={{ action: 'remove' }}
                                            variant="destructive"
                                        />
                                    )}
                                    {r.target?.type === 'creator' && (
                                        <NoteAction
                                            label="Ban"
                                            title="Ban the creator"
                                            url={`/admin/reports/${r.id}/resolve`}
                                            extra={{ action: 'ban' }}
                                            variant="destructive"
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    </article>
                ))}
            </div>
            <Pagination page={reports} />
        </PageContainer>
    );
}

AdminReports.layout = {
    breadcrumbs: [{ title: 'Reports', href: '/admin/reports' }],
};
