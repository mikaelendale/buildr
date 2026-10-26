import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SegmentedItem = {
    value: string;
    label: React.ReactNode;
    icon?: LucideIcon;
    /** Inertia link target; plain anchors (#section) use `anchor`. */
    href?: string;
    anchor?: boolean;
    count?: number;
};

const item =
    'relative inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-[13px] font-medium whitespace-nowrap transition-[color,background-color,box-shadow] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&_svg]:size-3.5';
const idle = 'text-muted-foreground hover:text-foreground';
const active =
    'bg-card text-foreground shadow-[0_1px_2px_oklch(0_0_0/0.08)] ring-1 ring-border/70';

/**
 * Segmented control on a grey track: tabs, filters and section nav.
 * Renders buttons, Inertia links, or in-page anchors.
 */
export default function Segmented({
    items,
    value,
    onChange,
    className,
    label,
}: {
    items: SegmentedItem[];
    value: string;
    onChange?: (value: string) => void;
    className?: string;
    label?: string;
}) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className={cn(
                'inline-flex max-w-full scrollbar-none items-center gap-0.5 overflow-x-auto rounded-[13px] bg-secondary p-[3px]',
                className,
            )}
        >
            {items.map((it) => {
                const selected = it.value === value;
                const content = (
                    <>
                        {it.icon && <it.icon />}
                        {it.label}
                        {it.count !== undefined && (
                            <span
                                className={cn(
                                    'font-mono text-[11px] tabular',
                                    selected
                                        ? 'text-muted-foreground'
                                        : 'text-ink-soft',
                                )}
                            >
                                {it.count}
                            </span>
                        )}
                    </>
                );
                const className = cn(item, selected ? active : idle);

                if (it.href && it.anchor) {
                    return (
                        <a
                            key={it.value}
                            href={it.href}
                            role="tab"
                            aria-selected={selected}
                            onClick={() => onChange?.(it.value)}
                            className={className}
                        >
                            {content}
                        </a>
                    );
                }

                if (it.href) {
                    return (
                        <Link
                            key={it.value}
                            href={it.href}
                            role="tab"
                            aria-selected={selected}
                            preserveScroll
                            className={className}
                        >
                            {content}
                        </Link>
                    );
                }

                return (
                    <button
                        key={it.value}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        onClick={() => onChange?.(it.value)}
                        className={className}
                    >
                        {content}
                    </button>
                );
            })}
        </div>
    );
}
