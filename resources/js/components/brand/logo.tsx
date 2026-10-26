import { cn } from '@/lib/utils';

/**
 * A white tile with an ink "b" and one mint dot: the same card-on-canvas
 * language as the rest of the site, shrunk to 32px.
 */
export function LogoMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 32 32"
            aria-hidden="true"
            className={cn('size-7 shrink-0', className)}
        >
            <rect
                x="0.5"
                y="0.5"
                width="31"
                height="31"
                rx="9"
                className="fill-card stroke-border"
            />
            <path
                d="M11.2 7.8v16.4"
                className="stroke-foreground"
                strokeWidth="2.6"
                strokeLinecap="round"
                fill="none"
            />
            <circle
                cx="16.9"
                cy="18.6"
                r="5.4"
                className="stroke-foreground"
                strokeWidth="2.6"
                fill="none"
            />
            <circle cx="23.6" cy="8.6" r="2.3" className="fill-brand" />
        </svg>
    );
}

export default function Logo({ className }: { className?: string }) {
    return (
        <span className={cn('flex items-center gap-2', className)}>
            <LogoMark className="drop-shadow-[0_1px_1px_oklch(0_0_0/0.06)]" />
            <span className="text-[17px] font-medium tracking-[-0.02em]">
                Buildr
            </span>
        </span>
    );
}
