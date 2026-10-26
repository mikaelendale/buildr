import { cn } from '@/lib/utils';

const SIZES = {
    xl: 'text-[38px] leading-[1.08] sm:text-[52px]',
    lg: 'text-[30px] leading-[1.12] sm:text-[40px]',
    md: 'text-[24px] leading-[1.2] sm:text-[28px]',
    sm: 'text-[20px] leading-[1.25]',
};

/**
 * Two-tone light headline: a soft grey lead-in, then the point in ink.
 * "find out who's" / "behind the channel."
 */
export default function Display({
    as: Tag = 'h2',
    lead,
    children,
    size = 'lg',
    className,
    stack = false,
}: {
    as?: 'h1' | 'h2' | 'h3' | 'p';
    lead?: React.ReactNode;
    children: React.ReactNode;
    size?: keyof typeof SIZES;
    className?: string;
    /** Put the lead on its own line. */
    stack?: boolean;
}) {
    return (
        <Tag
            className={cn(
                'font-light tracking-[-0.03em] text-balance',
                SIZES[size],
                className,
            )}
        >
            {lead && (
                <>
                    <span className="text-ink-soft">{lead}</span>
                    {stack ? <br /> : ' '}
                </>
            )}
            <span className="text-foreground">{children}</span>
        </Tag>
    );
}
