import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { TagChip } from '@/types';

export default function TagChips({
    tags,
    className,
}: {
    tags: TagChip[];
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap gap-2', className)}>
            {tags.map((tag) => (
                <Link
                    key={tag.slug}
                    href={`/tags/${tag.slug}`}
                    className="rounded-full bg-card px-3.5 py-1.5 text-sm text-foreground/80 shadow-[0_1px_2px_oklch(0_0_0/0.04)] ring-1 ring-border/80 transition-[color,box-shadow] duration-150 hover:text-foreground hover:ring-brand-ink/40"
                >
                    <span className="text-ink-soft">#</span>
                    {tag.name}
                </Link>
            ))}
        </div>
    );
}
