import { Link } from '@inertiajs/react';
import { Bars, Card, Counter } from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { StatPanel, TickBar } from '@/components/profile/stat-cards';
import { Badge } from '@/components/ui/badge';

type Props = {
    counters: {
        pending_reviews: number;
        failed_verification: number;
        open_reports: number;
        pending_disputes: number;
        failed_jobs: number;
        queued_jobs: number;
        scraping_paused: boolean;
        ai_paused: boolean;
        ai_spent_today: number;
        ai_budget: number;
        published_profiles: number;
        published_channels: number;
    };
    canary: { ok: boolean; message: string; checked_at: string } | null;
    charts: {
        date: string;
        signups: number;
        published: number;
        views: number;
    }[];
};

function RunState({ paused }: { paused: boolean }) {
    return paused ? (
        <Badge variant="amber">paused</Badge>
    ) : (
        <Badge variant="mint">running</Badge>
    );
}

export default function AdminDashboard({ counters, canary, charts }: Props) {
    const spent = counters.ai_spent_today;
    const budget = counters.ai_budget;

    return (
        <PageContainer
            title="Overview"
            description="What needs a decision today, and how the machinery is doing."
            wide
        >
            <section className="space-y-3">
                <h2 className="eyebrow">Needs attention</h2>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Counter
                        label="Pending reviews"
                        value={counters.pending_reviews}
                        href="/admin/review"
                        alert={counters.pending_reviews > 0}
                    />
                    <Counter
                        label="Failed verification"
                        value={counters.failed_verification}
                        href="/admin/review"
                        alert={counters.failed_verification > 0}
                    />
                    <Counter
                        label="Open reports"
                        value={counters.open_reports}
                        href="/admin/reports"
                        alert={counters.open_reports > 0}
                    />
                    <Counter
                        label="Disputes"
                        value={counters.pending_disputes}
                        href="/admin/claims"
                        alert={counters.pending_disputes > 0}
                    />
                </div>
            </section>

            <section className="space-y-3">
                <h2 className="eyebrow">Machinery</h2>
                <div className="grid gap-3 md:grid-cols-3">
                    <StatPanel
                        glow
                        title="AI spend today"
                        value={
                            <span className="font-mono text-xl">
                                ${spent.toFixed(2)}
                                <span className="ml-1 text-xs text-muted-foreground">
                                    / ${budget.toFixed(2)}
                                </span>
                            </span>
                        }
                        footer={
                            <>
                                <Link
                                    href="/admin/ai-costs"
                                    className="transition-colors hover:text-foreground"
                                >
                                    AI costs →
                                </Link>
                                {spent >= budget ? (
                                    <Badge variant="rose" className="bg-card">
                                        budget hit
                                    </Badge>
                                ) : (
                                    <RunState paused={counters.ai_paused} />
                                )}
                            </>
                        }
                    >
                        <TickBar value={budget > 0 ? spent / budget : 0} />
                    </StatPanel>

                    <StatPanel
                        title="Scraping"
                        value={<RunState paused={counters.scraping_paused} />}
                        footer={
                            canary ? (
                                <>
                                    <span className="truncate">
                                        Canary · {canary.message}
                                    </span>
                                    <span className="tabular shrink-0 font-mono text-[11px]">
                                        {new Date(
                                            canary.checked_at,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </span>
                                </>
                            ) : (
                                <span>No canary run yet</span>
                            )
                        }
                    >
                        <div className="flex items-center gap-2 text-sm">
                            <span
                                className={
                                    canary && !canary.ok
                                        ? 'size-2 rounded-full bg-rose ring-4 ring-rose-soft'
                                        : 'size-2 rounded-full bg-mint ring-4 ring-mint-soft'
                                }
                            />
                            {canary
                                ? canary.ok
                                    ? 'Telegram previews parse fine'
                                    : 'Canary failed: check the parser'
                                : 'Waiting for the first canary'}
                        </div>
                    </StatPanel>

                    <StatPanel
                        title="Queue"
                        value={
                            <span className="tabular font-mono text-xl">
                                {counters.queued_jobs}
                            </span>
                        }
                        footer={
                            <>
                                <Link
                                    href="/admin/jobs"
                                    className="transition-colors hover:text-foreground"
                                >
                                    Jobs →
                                </Link>
                                {counters.failed_jobs > 0 ? (
                                    <Badge variant="rose" className="bg-card">
                                        {counters.failed_jobs} failed
                                    </Badge>
                                ) : (
                                    <Badge variant="mint" className="bg-card">
                                        no failures
                                    </Badge>
                                )}
                            </>
                        }
                    >
                        <p className="text-sm text-muted-foreground">
                            Jobs waiting across scrape, ai, default and media.
                        </p>
                    </StatPanel>
                </div>
            </section>

            <section className="space-y-3">
                <div className="flex items-baseline justify-between gap-2">
                    <h2 className="eyebrow">Last 30 days</h2>
                    <span className="tabular font-mono text-xs text-muted-foreground">
                        {counters.published_profiles} profiles ·{' '}
                        {counters.published_channels} channels live
                    </span>
                </div>
                <div className="grid gap-3 md:grid-cols-3">
                    <Card>
                        <Bars
                            data={charts}
                            valueKey="signups"
                            label="Creator signups"
                        />
                    </Card>
                    <Card>
                        <Bars
                            data={charts}
                            valueKey="published"
                            label="Profiles published"
                        />
                    </Card>
                    <Card>
                        <Bars
                            data={charts}
                            valueKey="views"
                            label="Profile views"
                        />
                    </Card>
                </div>
            </section>
        </PageContainer>
    );
}

AdminDashboard.layout = { breadcrumbs: [{ title: 'Admin', href: '/admin' }] };
