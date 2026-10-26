import { router } from '@inertiajs/react';
import {
    DataTable,
    FilterSelect,
    Pagination,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import type { Paginated } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';

type Row = {
    id: number;
    username: string;
    purpose: string;
    status: string;
    code: string;
    attempts: number;
    found_in: string | null;
    user: { id: number; name: string; telegram: string | null };
    owner: {
        id: number;
        name: string;
        telegram_username: string | null;
    } | null;
    created_at: string | null;
    expires_at: string;
    verified_at: string | null;
};

const PURPOSE_TONE: Record<string, 'sky' | 'rose' | 'violet' | 'amber'> = {
    claim: 'sky',
    removal: 'rose',
    dispute: 'amber',
    rename: 'violet',
};

export default function AdminClaims({
    claims,
    filters,
}: {
    claims: Paginated<Row>;
    filters: { status?: string; purpose?: string };
}) {
    const filter = (key: string, value: string) =>
        router.get(
            '/admin/claims',
            { ...filters, [key]: value || undefined },
            { preserveState: true, replace: true },
        );

    return (
        <PageContainer
            title="Claims & disputes"
            description="In a dispute both people get a fresh code; whoever's code appears in the channel first keeps it."
            wide
        >
            <div className="flex flex-wrap gap-2">
                <FilterSelect
                    value={filters.status ?? ''}
                    onChange={(e) => filter('status', e.target.value)}
                >
                    {[
                        '',
                        'pending',
                        'verified',
                        'expired',
                        'failed',
                        'disputed',
                    ].map((s) => (
                        <option key={s} value={s}>
                            {s || 'Any status'}
                        </option>
                    ))}
                </FilterSelect>
                <FilterSelect
                    value={filters.purpose ?? ''}
                    onChange={(e) => filter('purpose', e.target.value)}
                >
                    {['', 'claim', 'removal', 'dispute', 'rename'].map((s) => (
                        <option key={s} value={s}>
                            {s || 'Any purpose'}
                        </option>
                    ))}
                </FilterSelect>
            </div>

            <DataTable
                columns={[
                    'Channel',
                    'Purpose',
                    'Status',
                    'Claimant',
                    'Current owner',
                    'Code',
                    'Created',
                    '',
                ]}
                empty="No claims match these filters."
                emptyGlyph="claim"
            >
                {claims.data.map((c) => (
                    <tr key={c.id}>
                        <td className="font-mono text-xs font-medium">
                            @{c.username}
                        </td>
                        <td>
                            <Badge
                                variant={PURPOSE_TONE[c.purpose] ?? 'outline'}
                            >
                                {c.purpose}
                            </Badge>
                        </td>
                        <td>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge value={c.status} />
                                {c.found_in && (
                                    <span className="text-xs text-muted-foreground">
                                        in {c.found_in}
                                    </span>
                                )}
                            </div>
                        </td>
                        <td>
                            {c.user.name}
                            {c.user.telegram && (
                                <span className="block font-mono text-xs text-muted-foreground">
                                    @{c.user.telegram}
                                </span>
                            )}
                        </td>
                        <td className="text-muted-foreground">
                            {c.owner ? (
                                <span className="text-foreground">
                                    {c.owner.name}
                                </span>
                            ) : (
                                '—'
                            )}
                        </td>
                        <td>
                            <span className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-xs">
                                {c.code}
                            </span>
                            <span className="tabular mt-1 block font-mono text-[11px] text-muted-foreground">
                                {c.attempts} checks
                            </span>
                        </td>
                        <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                            {formatDate(c.created_at)}
                        </td>
                        <td className="text-right whitespace-nowrap">
                            <div className="flex justify-end gap-1">
                                {c.status === 'disputed' && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            postAction(
                                                `/admin/claims/${c.id}/resolve`,
                                            )
                                        }
                                    >
                                        Start resolution
                                    </Button>
                                )}
                                {c.status === 'pending' && (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                postAction(
                                                    `/admin/claims/${c.id}/check`,
                                                )
                                            }
                                        >
                                            Check
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                postAction(
                                                    `/admin/claims/${c.id}/cancel`,
                                                )
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </>
                                )}
                            </div>
                        </td>
                    </tr>
                ))}
            </DataTable>
            <Pagination page={claims} />
        </PageContainer>
    );
}

AdminClaims.layout = {
    breadcrumbs: [{ title: 'Claims & disputes', href: '/admin/claims' }],
};
