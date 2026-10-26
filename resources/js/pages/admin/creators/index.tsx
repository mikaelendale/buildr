import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    FilterSelect,
    Pagination,
    StatusBadge,
} from '@/components/admin/ui';
import type { Paginated } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    slug: string;
    status: string;
    telegram: string | null;
    banned: boolean;
    channels: number;
    created_at: string | null;
};

export default function AdminCreators({
    creators,
    filters,
}: {
    creators: Paginated<Row>;
    filters: { q?: string; status?: string };
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const filter = (key: string, value: string) =>
        router.get(
            '/admin/creators',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );

    return (
        <PageContainer
            title="Creators"
            description="People who logged in and claimed a page."
            wide
        >
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
                        placeholder="Search name or slug"
                        className="h-9 w-64 rounded-full pl-10"
                    />
                </form>
                <FilterSelect
                    value={filters.status ?? ''}
                    onChange={(e) => filter('status', e.target.value)}
                >
                    {[
                        '',
                        'draft',
                        'pending_review',
                        'published',
                        'changes_requested',
                        'rejected',
                        'hidden',
                    ].map((s) => (
                        <option key={s} value={s}>
                            {s === '' ? 'Any status' : s.replace('_', ' ')}
                        </option>
                    ))}
                </FilterSelect>
            </div>

            <DataTable
                columns={[
                    'Creator',
                    'Telegram',
                    'Status',
                    { label: 'Channels', className: 'text-right' },
                    'Joined',
                ]}
                empty="No creators match these filters."
                emptyGlyph="profile"
            >
                {creators.data.map((c) => (
                    <tr key={c.id}>
                        <td>
                            <Link
                                href={`/admin/creators/${c.id}`}
                                className="font-medium hover:underline"
                            >
                                {c.name}
                            </Link>
                            <div className="font-mono text-xs text-muted-foreground">
                                /{c.slug}
                            </div>
                        </td>
                        <td className="font-mono text-xs text-muted-foreground">
                            {c.telegram ? `@${c.telegram}` : '—'}
                        </td>
                        <td>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge value={c.status} />
                                {c.banned && (
                                    <Badge variant="rose">banned</Badge>
                                )}
                            </div>
                        </td>
                        <td className="tabular text-right font-mono text-xs">
                            {c.channels}
                        </td>
                        <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                            {formatDate(c.created_at)}
                        </td>
                    </tr>
                ))}
            </DataTable>
            <Pagination page={creators} />
        </PageContainer>
    );
}

AdminCreators.layout = {
    breadcrumbs: [{ title: 'Creators', href: '/admin/creators' }],
};
