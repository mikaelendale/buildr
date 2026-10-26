import { Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Children, useState } from 'react';
import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

export function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1 text-[13px]">
            {page.links.map((link, i) => (
                <Link
                    key={i}
                    href={link.url ?? '#'}
                    preserveScroll
                    className={cn(
                        'tabular flex h-8 min-w-8 items-center justify-center rounded-full px-3 font-mono transition-colors',
                        link.active
                            ? 'bg-primary text-primary-foreground shadow-[0_1px_2px_oklch(0_0_0/0.12)]'
                            : 'text-muted-foreground hover:bg-card hover:text-foreground',
                        !link.url && 'pointer-events-none opacity-40',
                    )}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
            <span className="tabular ml-2 font-mono text-xs text-muted-foreground">
                {page.total} total
            </span>
        </nav>
    );
}

type Tone = 'mint' | 'sky' | 'amber' | 'rose' | 'violet' | 'outline';

const TONES: Record<string, Tone> = {
    published: 'mint',
    verified: 'mint',
    approved: 'mint',
    ok: 'mint',
    done: 'mint',
    claimed: 'sky',
    backfilling: 'sky',
    incremental: 'sky',
    running: 'sky',
    pending: 'amber',
    pending_review: 'amber',
    open: 'amber',
    queued: 'amber',
    changes_requested: 'amber',
    hidden: 'violet',
    draft: 'violet',
    failed: 'rose',
    failed_verification: 'rose',
    rejected: 'rose',
    removed: 'rose',
    disputed: 'rose',
    not_found: 'rose',
    banned: 'rose',
};

export function StatusBadge({ value }: { value: string | null | undefined }) {
    if (!value) {
        return null;
    }

    const tone = TONES[value] ?? 'outline';

    return (
        <Badge
            variant={tone}
            className={tone === 'outline' ? 'text-muted-foreground' : ''}
        >
            {value.replace(/_/g, ' ')}
        </Badge>
    );
}

export function Card({
    title,
    children,
    className,
    actions,
}: {
    title?: string;
    children: React.ReactNode;
    className?: string;
    actions?: React.ReactNode;
}) {
    return (
        <section className={cn('surface p-5', className)}>
            {(title || actions) && (
                <div className="mb-4 flex items-center justify-between gap-2">
                    {title && (
                        <h2 className="text-[15px] font-medium tracking-tight">
                            {title}
                        </h2>
                    )}
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}

/**
 * KPI tile in the tray style: a white card seated in a grey tray. Alerts
 * tint the tray rose and add a dot; links lift on hover.
 */
export function Counter({
    label,
    value,
    href,
    alert,
    hint,
}: {
    label: string;
    value: React.ReactNode;
    href?: string;
    alert?: boolean;
    hint?: React.ReactNode;
}) {
    const body = (
        <div
            className={cn(
                'h-full rounded-[20px] p-1 ring-1 transition-[box-shadow,transform] duration-200',
                alert
                    ? 'bg-rose-soft ring-rose/15'
                    : 'bg-secondary ring-border/60',
                href && 'group-hover:-translate-y-0.5 group-hover:shadow-soft',
            )}
        >
            <div className="rounded-2xl bg-card px-4 py-3.5 shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                <div className="tabular text-[26px] leading-none font-light tracking-tight">
                    {value}
                </div>
            </div>
            <div className="flex items-center justify-between gap-2 px-3 pt-2 pb-1.5 text-xs text-muted-foreground">
                <span className="flex min-w-0 items-center gap-1.5">
                    {alert && (
                        <span className="size-1.5 shrink-0 rounded-full bg-rose" />
                    )}
                    <span className="truncate">{label}</span>
                </span>
                {hint ??
                    (href && (
                        <ArrowUpRight className="size-3.5 shrink-0 text-ink-soft transition-colors group-hover:text-foreground" />
                    ))}
            </div>
        </div>
    );

    return href ? (
        <Link
            href={href}
            className="group rounded-[20px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {body}
        </Link>
    ) : (
        body
    );
}

export function Bars({
    data,
    valueKey,
    label,
    format = (v: number) => String(v),
}: {
    data: Record<string, number | string>[];
    valueKey: string;
    label: string;
    format?: (value: number) => string;
}) {
    const values = data.map((d) => Number(d[valueKey]) || 0);
    const max = Math.max(1, ...values);
    const total = values.reduce((a, b) => a + b, 0);

    return (
        <div>
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <p className="text-sm font-medium">{label}</p>
                <span className="tabular font-mono text-xs text-muted-foreground">
                    {format(total)}
                </span>
            </div>
            <div
                className="flex h-24 items-end gap-[3px] border-b border-border/70"
                role="img"
                aria-label={label}
            >
                {data.map((d) => {
                    const v = Number(d[valueKey]) || 0;

                    return (
                        <div
                            key={String(d.date)}
                            title={`${d.date}: ${format(v)}`}
                            className={cn(
                                'flex-1 rounded-t-[3px] transition-colors',
                                v > 0
                                    ? 'bg-chart-1/80 hover:bg-chart-1'
                                    : 'bg-border/70',
                            )}
                            style={{
                                height: `${Math.max(3, (v / max) * 100)}%`,
                            }}
                        />
                    );
                })}
            </div>
            {data.length > 1 && (
                <div className="mt-1.5 flex justify-between font-mono text-[10px] text-ink-soft">
                    <span>{String(data[0].date).slice(5)}</span>
                    <span>{String(data[data.length - 1].date).slice(5)}</span>
                </div>
            )}
        </div>
    );
}

/**
 * Grey tray with a label row on top and a white card holding the content
 * (usually a divided list), the same shape as DataTable.
 */
export function Tray({
    label,
    meta,
    children,
    className,
}: {
    label: React.ReactNode;
    meta?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'rounded-[20px] bg-secondary p-1 ring-1 ring-border/60',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2 px-3 pt-2 pb-2.5 text-xs text-muted-foreground">
                <span>{label}</span>
                {meta !== undefined && (
                    <span className="tabular font-mono">{meta}</span>
                )}
            </div>
            <div className="rounded-2xl bg-card shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                {children}
            </div>
        </section>
    );
}

/** Hairline glyph + one line, for empty lists and tables. */
export function EmptyState({
    glyph = 'empty',
    children,
    className,
}: {
    glyph?: GlyphName;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'group flex flex-col items-center gap-3 px-4 py-10 text-center text-sm text-muted-foreground',
                className,
            )}
        >
            <Glyph name={glyph} className="size-11" />
            <div className="max-w-xs text-pretty">{children}</div>
        </div>
    );
}

type Column = string | { label: React.ReactNode; className?: string };

/**
 * Table in a grey tray: column labels sit on the tray, rows on a white
 * rounded card inside it, with a quiet hover tint.
 */
export function DataTable({
    columns,
    children,
    empty,
    emptyGlyph,
    className,
}: {
    columns: Column[];
    children: React.ReactNode;
    empty?: React.ReactNode;
    emptyGlyph?: GlyphName;
    className?: string;
}) {
    const hasRows = Children.toArray(children).length > 0;

    return (
        <div
            className={cn(
                'rounded-[20px] bg-secondary p-1 ring-1 ring-border/60',
                className,
            )}
        >
            <div className="overflow-x-auto rounded-2xl">
                <table className="w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            {columns.map((col, i) => {
                                const c =
                                    typeof col === 'string'
                                        ? { label: col }
                                        : col;

                                return (
                                    <th
                                        key={i}
                                        className={cn(
                                            'px-3 pt-2 pb-2.5 text-left text-xs font-normal whitespace-nowrap text-muted-foreground',
                                            c.className,
                                        )}
                                    >
                                        {c.label}
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody className="[&>tr:first-child>td:first-child]:rounded-tl-2xl [&>tr:first-child>td:last-child]:rounded-tr-2xl [&>tr:hover>td]:bg-card/70 [&>tr:last-child>td:first-child]:rounded-bl-2xl [&>tr:last-child>td:last-child]:rounded-br-2xl [&>tr:not(:first-child)>td]:border-t [&>tr>td]:border-border/70 [&>tr>td]:bg-card [&>tr>td]:px-3 [&>tr>td]:py-3 [&>tr>td]:transition-colors">
                        {hasRows ? (
                            children
                        ) : (
                            <tr>
                                <td colSpan={columns.length}>
                                    <EmptyState glyph={emptyGlyph}>
                                        {empty ?? 'Nothing here yet.'}
                                    </EmptyState>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** Native select styled as a pill, for URL-backed filters. */
export function FilterSelect({
    className,
    ...props
}: React.ComponentProps<'select'>) {
    return (
        <select
            {...props}
            className={cn(
                'h-9 rounded-full bg-card px-3 pr-8 text-[13px] text-foreground/80 shadow-[0_1px_2px_oklch(0_0_0/0.04)] ring-1 ring-border/80 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
        />
    );
}

/**
 * Button that asks for a note (with optional templates) before posting.
 */
export function NoteAction({
    label,
    title,
    url,
    field = 'note',
    templates = [],
    variant = 'outline',
    required = true,
    extra = {},
    description,
}: {
    label: string;
    title: string;
    url: string;
    field?: string;
    templates?: string[];
    variant?: 'outline' | 'destructive' | 'default' | 'secondary';
    required?: boolean;
    extra?: Record<string, string | boolean>;
    description?: string;
}) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const submit = () => {
        setBusy(true);
        router.post(
            url,
            { [field]: note, ...extra },
            {
                preserveScroll: true,
                onError: (errors) => setError(Object.values(errors)[0]),
                onSuccess: () => setOpen(false),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Button size="sm" variant={variant} onClick={() => setOpen(true)}>
                {label}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="font-medium tracking-tight">
                            {title}
                        </DialogTitle>
                        {description && (
                            <DialogDescription>{description}</DialogDescription>
                        )}
                    </DialogHeader>
                    {templates.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {templates.map((t) => (
                                <button
                                    key={t}
                                    type="button"
                                    onClick={() => setNote(t)}
                                    className={cn(
                                        'rounded-full px-2.5 py-1 text-left text-xs ring-1 transition-colors',
                                        note === t
                                            ? 'bg-primary text-primary-foreground ring-primary'
                                            : 'bg-card text-foreground/75 ring-border/80 hover:text-foreground',
                                    )}
                                >
                                    {t}
                                </button>
                            ))}
                        </div>
                    )}
                    <Textarea
                        rows={4}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="Write a note…"
                    />
                    <InputError message={error} />
                    <DialogFooter>
                        <Button
                            variant={
                                variant === 'outline' ? 'default' : variant
                            }
                            disabled={busy || (required && note.trim() === '')}
                            onClick={submit}
                        >
                            {label}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function postAction(
    url: string,
    data: Record<string, string | boolean | number> = {},
) {
    router.post(url, data, { preserveScroll: true });
}
