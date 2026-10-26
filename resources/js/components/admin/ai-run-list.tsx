import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type AiRunRow = {
    id: number;
    job: string;
    model: string;
    status: string;
    tokens: number;
    duration_ms: number | null;
    error: string | null;
    at: string | null;
    channel?: string | null;
};

const STATUS_TONE: Record<string, 'mint' | 'rose' | 'amber'> = {
    ok: 'mint',
    error: 'rose',
    invalid: 'amber',
};

function ago(at: string | null): string {
    if (!at) {
        return '';
    }

    const seconds = Math.max(
        0,
        Math.round((Date.now() - new Date(at).getTime()) / 1000),
    );

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    return `${Math.floor(seconds / 3600)}h ago`;
}

/**
 * The latest AI calls: which step, which model answered, how long it took,
 * and the provider's error when it failed (rate limits, overloads).
 */
export default function AiRunList({
    runs,
    empty,
    showChannel = false,
}: {
    runs: AiRunRow[];
    empty: string;
    showChannel?: boolean;
}) {
    if (runs.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="divide-y divide-border/70 text-xs">
            {runs.map((run) => (
                <li key={run.id} className="py-2 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <Badge
                            variant={STATUS_TONE[run.status] ?? 'outline'}
                            className="font-mono"
                        >
                            {run.status}
                        </Badge>
                        <span className="font-mono font-medium">
                            {run.job.replace(/_/g, ' ')}
                        </span>
                        {showChannel && run.channel && (
                            <Link
                                href={`/admin/channels?q=${run.channel}`}
                                className="font-mono text-muted-foreground hover:text-foreground"
                            >
                                @{run.channel}
                            </Link>
                        )}
                        <span className="font-mono text-muted-foreground">
                            {run.model}
                        </span>
                        <span className="tabular ml-auto font-mono whitespace-nowrap text-ink-soft">
                            {run.tokens > 0 &&
                                `${run.tokens.toLocaleString()} tok · `}
                            {run.duration_ms !== null &&
                                `${(run.duration_ms / 1000).toFixed(1)}s · `}
                            {ago(run.at)}
                        </span>
                    </div>
                    {run.error && (
                        <p
                            className={cn(
                                'mt-1 line-clamp-2 font-mono text-[11px]',
                                run.status === 'error'
                                    ? 'text-rose'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {run.error}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}
