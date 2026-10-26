import { InfiniteScroll, router } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    Flame,
    Loader2,
    Sparkles,
    Users,
    X,
    Zap,
} from 'lucide-react';
import ProfileCard from '@/components/directory/profile-card';
import Glyph from '@/components/figures/glyph';
import { Button } from '@/components/ui/button';
import Segmented from '@/components/ui/segmented';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { CursorPage, ProfileCard as Card, TagChip } from '@/types';

export type DiscoverFilters = {
    tags: string[];
    claimed: boolean;
    lang: 'en' | 'am' | null;
    active: boolean;
    size: string | null;
    sort: 'trending' | 'newest' | 'subscribers' | 'active';
};

const SIZES: Record<string, string> = {
    lt1k: 'Under 1K',
    '1k-10k': '1K – 10K',
    '10k-50k': '10K – 50K',
    '50k+': '50K+',
};

const SORTS = [
    { value: 'trending', label: 'Trending', icon: Flame },
    { value: 'newest', label: 'Newest', icon: Sparkles },
    { value: 'subscribers', label: 'Biggest', icon: Users },
    { value: 'active', label: 'Most active', icon: Activity },
] as const;

/**
 * Discover grid with URL-backed filters (plan §9.3). Used by /discover and /tags/{slug}.
 */
export default function DiscoverGrid({
    path,
    filters,
    entries,
    tags,
    lockedTag,
}: {
    path: string;
    filters: DiscoverFilters;
    entries: CursorPage<Card>;
    tags: TagChip[];
    lockedTag?: string;
}) {
    const apply = (next: Partial<DiscoverFilters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string | string[]> = {};

        const tagList = merged.tags.filter((t) => t !== lockedTag);

        if (tagList.length) params.tags = tagList;
        if (merged.claimed) params.claimed = '1';
        if (merged.lang) params.lang = merged.lang;
        if (merged.active) params.active = '1';
        if (merged.size) params.size = merged.size;
        if (merged.sort !== 'trending') params.sort = merged.sort;

        router.get(path, params, {
            preserveScroll: false,
            replace: true,
            reset: ['entries'],
        });
    };

    const toggleTag = (slug: string) =>
        apply({
            tags: filters.tags.includes(slug)
                ? filters.tags.filter((t) => t !== slug)
                : [...filters.tags, slug],
        });

    const anyFilter = Boolean(
        filters.claimed ||
        filters.lang ||
        filters.active ||
        filters.size ||
        filters.tags.some((t) => t !== lockedTag),
    );

    const clear = () =>
        apply({
            tags: lockedTag ? [lockedTag] : [],
            claimed: false,
            lang: null,
            active: false,
            size: null,
        });

    const topics = tags.filter((t) => t.slug !== lockedTag);

    return (
        <div>
            <div className="sticky top-16 z-30 -mx-5 space-y-2.5 bg-background/85 px-5 py-3 backdrop-blur-md">
                <div className="flex flex-col gap-2.5 lg:flex-row lg:items-center lg:justify-between">
                    <Segmented
                        label="Sort"
                        value={filters.sort}
                        onChange={(v) =>
                            apply({ sort: v as DiscoverFilters['sort'] })
                        }
                        items={SORTS.map((s) => ({
                            value: s.value,
                            label: s.label,
                            icon: s.icon,
                        }))}
                    />

                    <div className="flex scrollbar-none items-center gap-1.5 overflow-x-auto">
                        <Toggle
                            active={filters.claimed}
                            onClick={() => apply({ claimed: !filters.claimed })}
                        >
                            <BadgeCheck className="size-3.5" />
                            Verified
                        </Toggle>
                        <Toggle
                            active={filters.active}
                            onClick={() => apply({ active: !filters.active })}
                        >
                            <Zap className="size-3.5" />
                            Active this month
                        </Toggle>
                        <Segmented
                            label="Language"
                            className="shrink-0"
                            value={filters.lang ?? 'any'}
                            onChange={(v) =>
                                apply({
                                    lang:
                                        v === 'any' ? null : (v as 'en' | 'am'),
                                })
                            }
                            items={[
                                { value: 'any', label: 'Any' },
                                { value: 'en', label: 'EN' },
                                { value: 'am', label: 'አማ' },
                            ]}
                        />
                        <Select
                            value={filters.size ?? 'any'}
                            onValueChange={(v) =>
                                apply({ size: v === 'any' ? null : v })
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                aria-label="Channel size"
                                className={cn(
                                    'h-8 w-auto shrink-0 rounded-full border-0 text-[13px] ring-1 ring-border/80',
                                    filters.size &&
                                        'bg-primary text-primary-foreground ring-0 [&_svg]:!text-primary-foreground',
                                )}
                            >
                                <SelectValue placeholder="Any size" />
                            </SelectTrigger>
                            <SelectContent align="end">
                                <SelectItem value="any">Any size</SelectItem>
                                {Object.entries(SIZES).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {(topics.length > 0 || anyFilter) && (
                    <div className="flex items-center gap-2">
                        <div className="-ml-5 flex min-w-0 flex-1 scrollbar-none gap-1.5 overflow-x-auto [mask-image:linear-gradient(to_right,transparent,#000_20px,#000_calc(100%-32px),transparent)] pl-5">
                            {topics.map((tag) => {
                                const on = filters.tags.includes(tag.slug);

                                return (
                                    <button
                                        key={tag.slug}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => toggleTag(tag.slug)}
                                        className={cn(
                                            'h-7 shrink-0 rounded-full px-2.5 text-[13px] transition-[color,background-color,box-shadow] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                            on
                                                ? 'bg-brand-soft text-brand-ink ring-1 ring-brand-ink/25'
                                                : 'text-muted-foreground hover:bg-card hover:text-foreground',
                                        )}
                                    >
                                        <span className="opacity-60">#</span>
                                        {tag.name}
                                    </button>
                                );
                            })}
                        </div>
                        {anyFilter && (
                            <button
                                type="button"
                                onClick={clear}
                                className="flex h-7 shrink-0 items-center gap-1 rounded-full px-2.5 text-[13px] text-muted-foreground transition-colors hover:bg-card hover:text-foreground"
                            >
                                <X className="size-3.5" />
                                Clear
                            </button>
                        )}
                    </div>
                )}
            </div>

            {entries.data.length === 0 ? (
                <div className="group surface mx-auto mt-10 flex max-w-md flex-col items-center p-8 text-center">
                    <Glyph name="search" className="size-14" />
                    <p className="mt-4 font-medium">
                        No profiles match these filters.
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Try fewer filters, or a different topic.
                    </p>
                    {anyFilter && (
                        <Button
                            variant="outline"
                            className="mt-5"
                            onClick={clear}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>
            ) : (
                <InfiniteScroll
                    data="entries"
                    className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    loading={
                        <div className="col-span-full flex justify-center py-6">
                            <Loader2 className="size-5 animate-spin text-muted-foreground" />
                        </div>
                    }
                >
                    {entries.data.map((card) => (
                        <ProfileCard key={card.url} card={card} />
                    ))}
                </InfiniteScroll>
            )}
        </div>
    );
}

function Toggle({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-[13px] transition-[color,background-color,box-shadow,transform] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:scale-[0.97]',
                active
                    ? 'bg-primary text-primary-foreground shadow-[0_1px_2px_oklch(0_0_0/0.12)]'
                    : 'bg-card text-foreground/75 shadow-[0_1px_2px_oklch(0_0_0/0.04)] ring-1 ring-border/80 hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
