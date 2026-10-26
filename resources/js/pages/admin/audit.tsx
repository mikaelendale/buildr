import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { DataTable, Pagination } from '@/components/admin/ui';
import type { Paginated } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    action: string;
    actor: string | null;
    actor_id: number | null;
    subject: string | null;
    meta: Record<string, unknown> | null;
    created_at: string | null;
};

export default function Audit({
    logs,
    filters,
}: {
    logs: Paginated<Row>;
    filters: { action?: string; actor?: string };
}) {
    const [action, setAction] = useState(filters.action ?? '');

    return (
        <PageContainer
            title="Audit log"
            description="Every admin action (and key creator events)."
            wide
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get(
                        '/admin/audit',
                        { ...filters, action: action || undefined },
                        { preserveState: true },
                    );
                }}
                className="relative max-w-md"
            >
                <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-soft" />
                <Input
                    value={action}
                    onChange={(e) => setAction(e.target.value)}
                    placeholder="Filter by action prefix, e.g. creator. or channel.removed"
                    className="pl-10"
                />
            </form>

            <DataTable
                columns={['When', 'Action', 'Who', 'Subject', 'Details']}
                empty="No matching entries."
                emptyGlyph="search"
            >
                {logs.data.map((l) => (
                    <tr key={l.id} className="align-top">
                        <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                            {formatDate(l.created_at)}
                        </td>
                        <td>
                            <span className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-xs">
                                {l.action}
                            </span>
                        </td>
                        <td>
                            {l.actor_id ? (
                                <button
                                    type="button"
                                    className="text-sm hover:underline"
                                    onClick={() =>
                                        router.get('/admin/audit', {
                                            ...filters,
                                            actor: l.actor_id ?? undefined,
                                        })
                                    }
                                >
                                    {l.actor}
                                </button>
                            ) : (
                                <span className="font-mono text-xs text-ink-soft">
                                    system
                                </span>
                            )}
                        </td>
                        <td className="font-mono text-xs">{l.subject}</td>
                        <td className="max-w-md">
                            <pre className="font-mono text-[11px] leading-relaxed whitespace-pre-wrap text-muted-foreground [overflow-wrap:anywhere]">
                                {l.meta ? JSON.stringify(l.meta) : ''}
                            </pre>
                        </td>
                    </tr>
                ))}
            </DataTable>
            <Pagination page={logs} />
        </PageContainer>
    );
}

Audit.layout = { breadcrumbs: [{ title: 'Audit log', href: '/admin/audit' }] };
