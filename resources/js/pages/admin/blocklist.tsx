import { router, useForm } from '@inertiajs/react';
import { Card, DataTable } from '@/components/admin/ui';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';

type Entry = {
    id: number;
    username: string;
    reason: string | null;
    by: string | null;
    created_at: string | null;
};

export default function Blocklist({ entries }: { entries: Entry[] }) {
    const form = useForm({ username: '', reason: '' });

    return (
        <PageContainer
            title="Blocklist"
            description="Blocklisted usernames are never scraped, even if a job was already queued."
        >
            <Card title="Block a username">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/blocklist', {
                            onSuccess: () => form.reset(),
                        });
                    }}
                    className="flex flex-wrap gap-2"
                >
                    <Input
                        className="w-56 font-mono"
                        value={form.data.username}
                        onChange={(e) =>
                            form.setData('username', e.target.value)
                        }
                        placeholder="@username"
                    />
                    <Input
                        className="min-w-48 flex-1"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="Reason"
                    />
                    <Button
                        type="submit"
                        className="h-10"
                        disabled={form.processing}
                    >
                        Block
                    </Button>
                </form>
                <InputError message={form.errors.username} className="mt-2" />
            </Card>

            <DataTable
                columns={[
                    'Username',
                    'Reason',
                    'Added',
                    { label: '', className: 'w-24' },
                ]}
                empty="Nothing is blocklisted."
                emptyGlyph="shield"
            >
                {entries.map((e) => (
                    <tr key={e.id}>
                        <td className="font-mono text-xs font-medium">
                            @{e.username}
                        </td>
                        <td className="text-muted-foreground">{e.reason}</td>
                        <td className="tabular font-mono text-xs whitespace-nowrap text-muted-foreground">
                            {e.by ?? 'system'} · {formatDate(e.created_at)}
                        </td>
                        <td className="text-right">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(`/admin/blocklist/${e.id}`, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Unblock
                            </Button>
                        </td>
                    </tr>
                ))}
            </DataTable>
        </PageContainer>
    );
}

Blocklist.layout = {
    breadcrumbs: [{ title: 'Blocklist', href: '/admin/blocklist' }],
};
