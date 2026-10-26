import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import Display from '@/components/brand/display';

/**
 * Section title in the two-tone voice: "Trending" (grey) "this week." (ink).
 */
export default function SectionHeading({
    lead,
    title,
    description,
    href,
    linkLabel = 'See all',
}: {
    lead?: string;
    title: string;
    description?: string;
    href?: string;
    linkLabel?: string;
}) {
    return (
        <div className="mb-5 flex items-end justify-between gap-4">
            <div>
                <Display size="sm" lead={lead}>
                    {title}
                </Display>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {href && (
                <Link
                    href={href}
                    className="group flex shrink-0 items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    {linkLabel}
                    <ArrowRight className="size-3.5 transition-transform duration-150 group-hover:translate-x-0.5" />
                </Link>
            )}
        </div>
    );
}
