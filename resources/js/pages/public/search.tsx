import { Form, Head, Link } from '@inertiajs/react';
import { ArrowUpRight, BadgeCheck, Users } from 'lucide-react';
import Display from '@/components/brand/display';
import ProfileAvatar from '@/components/directory/profile-avatar';
import SearchBox from '@/components/directory/search-box';
import TagChips from '@/components/directory/tag-chips';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ProfileCard as Card, TagChip } from '@/types';

type Props = {
    query: string;
    results: Card[];
    handle: string | null;
    suggestions?: Card[];
    tags?: TagChip[];
};

/**
 * One search hit as a scannable row: who, which channel, what they're
 * about, how big. The best match gets a little more room.
 */
function ResultRow({ card, best = false }: { card: Card; best?: boolean }) {
    const primary = card.channels[0];

    return (
        <Link
            href={card.url}
            className={cn(
                'group flex items-center gap-4 px-4 py-3.5 transition-colors outline-none hover:bg-background/60 focus-visible:bg-background/60',
                best && 'py-5',
            )}
        >
            <ProfileAvatar
                name={card.name}
                src={card.avatar}
                className={cn('ring-[3px]', best ? 'size-14' : 'size-11')}
            />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    <span
                        className={cn(
                            'truncate font-medium tracking-tight',
                            best && 'text-[17px]',
                        )}
                    >
                        {card.name}
                    </span>
                    {card.is_claimed && (
                        <BadgeCheck
                            className="size-4 shrink-0 text-brand-ink"
                            aria-label="Verified"
                        />
                    )}
                    {primary && (
                        <span className="hidden truncate font-mono text-xs text-muted-foreground sm:inline">
                            @{primary.username}
                        </span>
                    )}
                    {card.owner && (
                        <span className="hidden shrink-0 text-xs text-ink-soft sm:inline">
                            by {card.owner.name}
                        </span>
                    )}
                </div>
                {card.hook && (
                    <p
                        className={cn(
                            'mt-0.5 text-sm text-pretty text-muted-foreground',
                            best ? 'line-clamp-2' : 'line-clamp-1',
                        )}
                    >
                        {card.hook}
                    </p>
                )}
                {best && card.vibe_tags.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {card.vibe_tags.slice(0, 3).map((tag) => (
                            <Badge key={tag} variant="sky">
                                {tag}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>
            <span className="tabular hidden shrink-0 items-center gap-1 font-mono text-[11px] text-muted-foreground sm:flex">
                <Users className="size-3" />
                {formatCount(card.subscribers)}
            </span>
            <ArrowUpRight className="size-4 shrink-0 text-ink-soft transition-[color,transform] duration-150 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-foreground" />
        </Link>
    );
}

function ResultList({ cards, label }: { cards: Card[]; label: string }) {
    return (
        <div className="rounded-[20px] bg-secondary p-1 ring-1 ring-border/60">
            <div className="eyebrow px-4 pt-2 pb-2.5">{label}</div>
            <div className="divide-y divide-border/70 overflow-hidden rounded-2xl bg-card shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                {cards.map((card) => (
                    <ResultRow key={card.url} card={card} />
                ))}
            </div>
        </div>
    );
}

export default function SearchPage({
    query,
    results,
    handle,
    suggestions = [],
    tags = [],
}: Props) {
    const [best, ...rest] = results;

    return (
        <>
            <Head title={query ? `Search: ${query}` : 'Search'}>
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>

            <div className="mx-auto max-w-[820px] px-5 pt-10 sm:pt-14">
                <Display
                    as="h1"
                    size="lg"
                    lead={query ? 'Results for' : 'Search'}
                >
                    {query ? <>“{query}”.</> : 'any channel.'}
                </Display>

                <SearchBox
                    defaultValue={query}
                    autoFocus={query === ''}
                    className="mt-6"
                />
                <p className="mt-2.5 text-xs text-muted-foreground">
                    Names, @handles and t.me links all work.
                </p>

                {best && (
                    <div className="mt-10 space-y-4">
                        <div className="rounded-[20px] bg-secondary p-1 ring-1 ring-border/60">
                            <div className="flex items-center justify-between px-4 pt-2 pb-2.5">
                                <span className="eyebrow">Best match</span>
                                <span className="tabular font-mono text-[11px] text-muted-foreground">
                                    {results.length} result
                                    {results.length === 1 ? '' : 's'}
                                </span>
                            </div>
                            <div className="overflow-hidden rounded-2xl bg-card shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                                <ResultRow card={best} best />
                            </div>
                        </div>

                        {rest.length > 0 && (
                            <ResultList cards={rest} label="More results" />
                        )}
                    </div>
                )}

                {query !== '' && results.length === 0 && (
                    <div className="group surface mt-10 p-6 sm:p-8">
                        <div className="flex items-start gap-4">
                            <Glyph name="empty" className="size-12" />
                            <div>
                                <h2 className="text-xl font-normal tracking-tight">
                                    No one here yet.
                                </h2>
                                <p className="mt-1 text-sm text-pretty text-muted-foreground">
                                    Know a channel that should be listed?
                                    Suggest it and we&apos;ll take a look.
                                </p>
                            </div>
                        </div>

                        <Form
                            action="/channel-suggestions"
                            method="post"
                            className="mt-6"
                            resetOnSuccess
                        >
                            {({ processing, errors }) => (
                                <div className="space-y-2">
                                    <div className="flex gap-2">
                                        <Input
                                            name="username"
                                            defaultValue={
                                                handle ? `@${handle}` : ''
                                            }
                                            placeholder="@channel or t.me link"
                                            aria-label="Channel to suggest"
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="h-10"
                                        >
                                            Suggest
                                        </Button>
                                    </div>
                                    <InputError message={errors.username} />
                                </div>
                            )}
                        </Form>

                        <p className="mt-5 text-sm text-muted-foreground">
                            Is it yours?{' '}
                            <Link
                                href="/claim"
                                className="font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                Claim it
                            </Link>
                        </p>
                    </div>
                )}

                {results.length === 0 &&
                    (tags.length > 0 || suggestions.length > 0) && (
                        <div className="mt-12 space-y-10">
                            {tags.length > 0 && (
                                <section>
                                    <div className="eyebrow mb-3">
                                        Browse by topic
                                    </div>
                                    <TagChips tags={tags} />
                                </section>
                            )}
                            {suggestions.length > 0 && (
                                <ResultList
                                    cards={suggestions}
                                    label={
                                        query
                                            ? 'Trending instead'
                                            : 'Trending builders'
                                    }
                                />
                            )}
                        </div>
                    )}
            </div>
        </>
    );
}
