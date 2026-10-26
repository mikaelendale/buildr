import { Link, useForm } from '@inertiajs/react';
import {
    Bars,
    Card,
    Counter,
    DataTable,
    postAction,
} from '@/components/admin/ui';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { StatPanel, TickBar } from '@/components/profile/stat-cards';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    today: number;
    budget: number;
    paused: boolean;
    total30: number;
    byDay: { date: string; cost: number }[];
    byJob: {
        job: string;
        calls: number;
        input_tokens: number;
        output_tokens: number;
        cost: number;
    }[];
    byChannel: {
        channel: string | null;
        id: number;
        cost: number;
        calls: number;
    }[];
    quota?: {
        until: string | null;
        reason: string | null;
        channels: {
            id: number;
            username: string | null;
            step: string;
            until: string;
        }[];
    } | null;
};

const usd = (n: number, digits = 3) => `$${Number(n).toFixed(digits)}`;

export default function AiCosts({
    today,
    budget,
    paused,
    total30,
    byDay,
    byJob,
    byChannel,
    quota,
}: Props) {
    const form = useForm({ budget: String(budget) });
    const hit = today >= budget;

    return (
        <PageContainer
            title="AI costs"
            description="Every model call is logged with tokens and an estimated cost."
            wide
            actions={
                <Button
                    size="sm"
                    variant={paused ? 'default' : 'destructive'}
                    onClick={() => postAction('/admin/ai-costs/toggle')}
                >
                    {paused ? 'Resume AI jobs' : 'Pause AI jobs'}
                </Button>
            }
        >
            {quota && quota.channels.length > 0 && (
                <div className="surface flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <Badge variant="amber">provider limit</Badge>
                            <span className="text-sm font-medium">
                                {quota.channels.length === 1
                                    ? '1 channel is waiting for AI quota'
                                    : `${quota.channels.length} channels are waiting for AI quota`}
                            </span>
                        </div>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            They resume on their own
                            {quota.until
                                ? ` around ${new Date(quota.until).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                                : ''}
                            , from the step they stopped at. Resume now after
                            upgrading the plan or switching models.
                        </p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {quota.channels.map((c) => (
                                <span
                                    key={c.id}
                                    className="rounded-full bg-secondary px-2.5 py-0.5 font-mono text-[11px] text-foreground/75"
                                >
                                    @{c.username ?? c.id} · {c.step}
                                </span>
                            ))}
                        </div>
                        {quota.reason && (
                            <p className="mt-2 line-clamp-2 font-mono text-[11px] text-ink-soft">
                                {quota.reason}
                            </p>
                        )}
                    </div>
                    <Button
                        size="sm"
                        className="shrink-0"
                        onClick={() => postAction('/admin/ai-costs/resume')}
                    >
                        Resume now
                    </Button>
                </div>
            )}

            <div className="grid gap-3 md:grid-cols-[1.4fr_1fr_1fr]">
                <StatPanel
                    glow
                    title="Spent today"
                    value={
                        <span className="font-mono text-xl">
                            {usd(today)}
                            <span className="ml-1 text-xs text-muted-foreground">
                                / {usd(budget, 2)}
                            </span>
                        </span>
                    }
                    footer={
                        <>
                            <span className="tabular font-mono">
                                {budget > 0
                                    ? `${Math.round((today / budget) * 100)}% of the daily budget`
                                    : 'No budget set'}
                            </span>
                            {paused ? (
                                <Badge variant="amber" className="bg-card">
                                    paused
                                </Badge>
                            ) : hit ? (
                                <Badge variant="rose" className="bg-card">
                                    budget hit
                                </Badge>
                            ) : (
                                <Badge variant="mint" className="bg-card">
                                    running
                                </Badge>
                            )}
                        </>
                    }
                >
                    <TickBar value={budget > 0 ? today / budget : 0} />
                </StatPanel>
                <Counter
                    label="Daily budget"
                    value={<span className="font-mono">{usd(budget, 2)}</span>}
                />
                <Counter
                    label="Last 30 days"
                    value={<span className="font-mono">{usd(total30, 2)}</span>}
                />
            </div>

            <Card>
                <Bars
                    data={byDay}
                    valueKey="cost"
                    label="Spend per day"
                    format={(v) => usd(v)}
                />
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="space-y-2">
                    <h2 className="eyebrow">By job type</h2>
                    <DataTable
                        columns={[
                            'Job',
                            { label: 'Calls', className: 'text-right' },
                            {
                                label: 'Tokens in / out',
                                className: 'text-right',
                            },
                            { label: 'Cost', className: 'text-right' },
                        ]}
                        empty="No AI calls yet."
                        emptyGlyph="chart"
                    >
                        {byJob.map((j) => (
                            <tr key={j.job}>
                                <td className="font-mono text-xs">{j.job}</td>
                                <td className="tabular text-right font-mono text-xs">
                                    {j.calls}
                                </td>
                                <td className="tabular text-right font-mono text-xs text-muted-foreground">
                                    {Number(j.input_tokens).toLocaleString()} /{' '}
                                    {Number(j.output_tokens).toLocaleString()}
                                </td>
                                <td className="tabular text-right font-mono text-xs">
                                    {usd(j.cost)}
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                </div>

                <div className="space-y-2">
                    <h2 className="eyebrow">Top channels</h2>
                    <DataTable
                        columns={[
                            'Channel',
                            { label: 'Calls', className: 'text-right' },
                            { label: 'Cost', className: 'text-right' },
                        ]}
                        empty="No channel has been processed yet."
                        emptyGlyph="feed"
                    >
                        {byChannel.map((c) => (
                            <tr key={c.id}>
                                <td>
                                    <Link
                                        href={`/admin/channels/${c.id}`}
                                        className="font-mono text-xs hover:underline"
                                    >
                                        @{c.channel ?? c.id}
                                    </Link>
                                </td>
                                <td className="tabular text-right font-mono text-xs">
                                    {c.calls}
                                </td>
                                <td className="tabular text-right font-mono text-xs">
                                    {usd(c.cost)}
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                </div>
            </div>

            <Card title="Daily budget">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put('/admin/ai-costs/budget', {
                            preserveScroll: true,
                        });
                    }}
                    className="flex max-w-sm gap-2"
                >
                    <div className="relative flex-1">
                        <span className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 font-mono text-sm text-muted-foreground">
                            $
                        </span>
                        <Input
                            type="number"
                            step="0.5"
                            min="0"
                            value={form.data.budget}
                            onChange={(e) =>
                                form.setData('budget', e.target.value)
                            }
                            className="tabular pl-7 font-mono"
                        />
                    </div>
                    <Button
                        type="submit"
                        className="h-10"
                        disabled={form.processing}
                    >
                        Save
                    </Button>
                </form>
                <InputError message={form.errors.budget} />
                <p className="mt-3 text-xs text-pretty text-muted-foreground">
                    When today&apos;s spend reaches the budget, AI jobs pause
                    until tomorrow and you get a Telegram alert.
                </p>
            </Card>
        </PageContainer>
    );
}

AiCosts.layout = {
    breadcrumbs: [{ title: 'AI costs', href: '/admin/ai-costs' }],
};
