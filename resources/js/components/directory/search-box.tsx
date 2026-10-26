import { router } from '@inertiajs/react';
import { ArrowRight, BadgeCheck, Loader2, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';

type Suggestion = {
    name: string;
    url: string;
    avatar: string | null;
    channel: string | null;
    subscribers: number;
    is_claimed: boolean;
};

/**
 * Hero search with instant autocomplete (plan §9.1): avatar + name + @channel
 * + subscribers, keyboard navigable, accepts pasted t.me links.
 */
export default function SearchBox({
    defaultValue = '',
    size = 'lg',
    autoFocus = false,
    className,
}: {
    defaultValue?: string;
    size?: 'lg' | 'md';
    autoFocus?: boolean;
    className?: string;
}) {
    const [query, setQuery] = useState(defaultValue);
    const [results, setResults] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(-1);
    const listId = useId();
    const controller = useRef<AbortController | null>(null);

    useEffect(() => {
        const q = query.trim();

        if (q.length < 2) {
            setResults([]);
            setLoading(false);

            return;
        }

        const timer = window.setTimeout(async () => {
            controller.current?.abort();
            controller.current = new AbortController();
            setLoading(true);

            try {
                const response = await fetch(
                    `/api/search/suggest?q=${encodeURIComponent(q)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.current.signal,
                    },
                );
                const data = (await response.json()) as {
                    results: Suggestion[];
                };
                setResults(data.results ?? []);
                setActive(-1);
            } catch {
                // Aborted or offline: keep the previous suggestions.
            } finally {
                setLoading(false);
            }
        }, 150);

        return () => window.clearTimeout(timer);
    }, [query]);

    const go = (url: string) => {
        setOpen(false);
        router.visit(url);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (active >= 0 && results[active]) {
            go(results[active].url);

            return;
        }

        if (query.trim() !== '') {
            go(`/search?q=${encodeURIComponent(query.trim())}`);
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (!open || results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((i) => (i + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((i) => (i <= 0 ? results.length - 1 : i - 1));
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    const showList =
        open && query.trim().length >= 2 && (results.length > 0 || !loading);

    return (
        <form
            onSubmit={submit}
            role="search"
            className={cn('relative w-full', className)}
        >
            <div
                className={cn(
                    'flex items-center gap-3 rounded-2xl border border-border/80 bg-card shadow-soft transition-[border-color,box-shadow] duration-150 focus-within:border-ring focus-within:ring-4 focus-within:ring-ring/20',
                    size === 'lg' ? 'h-14 pr-2 pl-4' : 'h-11 px-3',
                )}
            >
                {loading ? (
                    <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" />
                ) : (
                    <Search className="size-5 shrink-0 text-muted-foreground" />
                )}
                <input
                    type="search"
                    name="q"
                    value={query}
                    autoFocus={autoFocus}
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="Search a name, channel, or @handle"
                    aria-label="Search creators and channels"
                    aria-expanded={showList}
                    aria-controls={listId}
                    aria-activedescendant={
                        active >= 0 ? `${listId}-${active}` : undefined
                    }
                    role="combobox"
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                    onKeyDown={onKeyDown}
                    className={cn(
                        'w-full bg-transparent outline-none placeholder:text-ink-soft [&::-webkit-search-cancel-button]:hidden',
                        size === 'lg' ? 'text-base' : 'text-sm',
                    )}
                />
                {size === 'lg' && (
                    <button
                        type="submit"
                        aria-label="Search"
                        className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground transition-transform duration-150 active:scale-95"
                    >
                        <ArrowRight className="size-4" />
                    </button>
                )}
            </div>

            {showList && (
                <ul
                    id={listId}
                    role="listbox"
                    className="animate-rise absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-border/80 bg-popover p-1.5 shadow-lift"
                >
                    {results.length === 0 ? (
                        <li className="px-3 py-3 text-sm text-muted-foreground">
                            No one here yet. Press Enter to search everything.
                        </li>
                    ) : (
                        results.map((result, i) => (
                            <li
                                key={result.url}
                                id={`${listId}-${i}`}
                                role="option"
                                aria-selected={i === active}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => go(result.url)}
                                onMouseEnter={() => setActive(i)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 transition-colors duration-100',
                                    i === active && 'bg-secondary',
                                )}
                            >
                                <ProfileAvatar
                                    name={result.name}
                                    src={result.avatar}
                                    className="size-9"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1 text-sm font-medium">
                                        <span className="truncate">
                                            {result.name}
                                        </span>
                                        {result.is_claimed && (
                                            <BadgeCheck className="size-3.5 shrink-0 text-brand-ink" />
                                        )}
                                    </div>
                                    {result.channel && (
                                        <div className="truncate font-mono text-xs text-muted-foreground">
                                            @{result.channel}
                                        </div>
                                    )}
                                </div>
                                <span className="tabular shrink-0 font-mono text-[11px] text-muted-foreground">
                                    {formatCount(result.subscribers)}
                                </span>
                            </li>
                        ))
                    )}
                </ul>
            )}
        </form>
    );
}
