import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import { Card, EmptyState, postAction, Tray } from '@/components/admin/ui';
import ProfileAvatar from '@/components/directory/profile-avatar';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ProfileCard } from '@/types';

type Props = {
    items: {
        id: number;
        type: string;
        listable_id: number;
        card: ProfileCard | null;
    }[];
    query: string;
    results: (ProfileCard & { listable_type: string; listable_id: number })[];
};

export default function Featured({ items, query, results }: Props) {
    const [q, setQ] = useState(query);

    const move = (index: number, delta: number) => {
        const ids = items.map((i) => i.id);
        const target = index + delta;

        if (target < 0 || target >= ids.length) return;

        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post(
            '/admin/featured/reorder',
            { ids },
            { preserveScroll: true },
        );
    };

    return (
        <PageContainer
            title="Featured"
            description="The landing page carousel, in this order."
        >
            <Tray label="On the landing page" meta={items.length}>
                {items.length === 0 ? (
                    <EmptyState glyph="spark">
                        Nothing featured yet. Search below to add a profile.
                    </EmptyState>
                ) : (
                    <ol className="divide-y divide-border/70">
                        {items.map((item, i) => (
                            <li
                                key={item.id}
                                className="flex items-center gap-3 px-3 py-2.5"
                            >
                                <span className="tabular w-6 text-center font-mono text-xs text-ink-soft">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                {item.card ? (
                                    <>
                                        <ProfileAvatar
                                            name={item.card.name}
                                            src={item.card.avatar}
                                            className="size-9"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {item.card.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.card.hook}
                                            </p>
                                        </div>
                                    </>
                                ) : (
                                    <p className="flex-1 text-sm text-muted-foreground">
                                        <span className="font-mono text-xs">
                                            {item.type} #{item.listable_id}
                                        </span>{' '}
                                        (not public right now)
                                    </p>
                                )}
                                <div className="flex shrink-0 items-center">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label="Up"
                                        onClick={() => move(i, -1)}
                                        disabled={i === 0}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label="Down"
                                        onClick={() => move(i, 1)}
                                        disabled={i === items.length - 1}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8 hover:text-rose"
                                        aria-label="Remove"
                                        onClick={() =>
                                            router.delete(
                                                `/admin/featured/${item.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </Tray>

            <Card title="Add">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/featured',
                            { q },
                            { preserveState: true },
                        );
                    }}
                    className="relative mb-2"
                >
                    <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-ink-soft" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search published profiles and channels"
                        className="pl-10"
                    />
                </form>
                {query !== '' && results.length === 0 && (
                    <EmptyState glyph="search" className="py-6">
                        No published profile matches “{query}”.
                    </EmptyState>
                )}
                <ul className="divide-y divide-border/70">
                    {results.map((r) => (
                        <li
                            key={r.url}
                            className="flex items-center gap-3 py-2.5"
                        >
                            <ProfileAvatar
                                name={r.name}
                                src={r.avatar}
                                className="size-9"
                            />
                            <span className="flex-1 truncate text-sm">
                                {r.name}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    postAction('/admin/featured', {
                                        type: r.listable_type,
                                        id: r.listable_id,
                                    })
                                }
                            >
                                <Plus /> Feature
                            </Button>
                        </li>
                    ))}
                </ul>
            </Card>
        </PageContainer>
    );
}

Featured.layout = {
    breadcrumbs: [{ title: 'Featured', href: '/admin/featured' }],
};
