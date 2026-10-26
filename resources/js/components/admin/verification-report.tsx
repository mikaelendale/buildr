import { EmptyState } from '@/components/admin/ui';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Report = {
    passed?: boolean;
    checked_at?: string;
    dropped?: { section: string; item: unknown; reason: string | null }[];
    llm?: Record<string, { verdict: string; reason: string | null }>;
} | null;

function itemText(item: unknown): string {
    if (typeof item === 'string') return item;
    if (Array.isArray(item)) return item.join(', ');
    if (item && typeof item === 'object') {
        const o = item as Record<string, unknown>;

        return String(
            o.line ??
                o.story ??
                o.why ??
                o.quote ??
                o.text ??
                JSON.stringify(o),
        );
    }

    return '';
}

const VERDICT_TONE: Record<string, 'mint' | 'rose' | 'amber'> = {
    supported: 'mint',
    unsupported: 'rose',
    sensitive: 'amber',
};

/**
 * Verification report for the admin review view (plan §8.7 #4, §10.2).
 */
export default function VerificationReport({ report }: { report: Report }) {
    if (!report) {
        return (
            <EmptyState glyph="shield" className="py-6">
                No verification report yet.
            </EmptyState>
        );
    }

    const llm = Object.entries(report.llm ?? {});
    const supported = llm.filter(([, v]) => v.verdict === 'supported').length;

    return (
        <div className="space-y-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                {report.passed ? (
                    <Badge variant="mint">passed</Badge>
                ) : (
                    <Badge variant="rose">failed</Badge>
                )}
                {llm.length > 0 && (
                    <span className="tabular font-mono text-xs text-muted-foreground">
                        {supported}/{llm.length} claims supported
                    </span>
                )}
                {report.checked_at && (
                    <span className="tabular ml-auto font-mono text-[11px] text-ink-soft">
                        {new Date(report.checked_at).toLocaleString()}
                    </span>
                )}
            </div>

            {(report.dropped ?? []).length > 0 && (
                <div className="space-y-2">
                    <p className="eyebrow">Dropped</p>
                    <ul className="space-y-1.5">
                        {report.dropped?.map((d, i) => (
                            <li
                                key={i}
                                className="rounded-xl bg-rose-soft/70 px-3 py-2 ring-1 ring-rose/10"
                            >
                                <span className="font-mono text-[11px] text-muted-foreground">
                                    {d.section}
                                </span>
                                <p className="mt-0.5 text-[13px] text-pretty text-foreground/85">
                                    {itemText(d.item)}
                                </p>
                                {d.reason && (
                                    <span className="mt-1 block text-xs text-rose">
                                        {d.reason}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {llm.length > 0 && (
                <div className="space-y-2">
                    <p className="eyebrow">Claim checks</p>
                    <ul className="grid gap-1 sm:grid-cols-2">
                        {llm.map(([id, v]) => (
                            <li
                                key={id}
                                title={v.reason ?? undefined}
                                className={cn(
                                    'flex items-center justify-between gap-2 rounded-lg bg-secondary/70 px-2.5 py-1.5 text-xs',
                                )}
                            >
                                <span className="truncate font-mono">{id}</span>
                                <Badge
                                    variant={VERDICT_TONE[v.verdict] ?? 'rose'}
                                    className="bg-card"
                                >
                                    {v.verdict}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
