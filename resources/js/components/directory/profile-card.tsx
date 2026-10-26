import { Link } from '@inertiajs/react';
import { ArrowUpRight, BadgeCheck, Users } from 'lucide-react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import { Badge } from '@/components/ui/badge';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ProfileCard as Card } from '@/types';

const TAG_TONES = ['sky', 'violet', 'amber'] as const;

export default function ProfileCard({
    card,
    className,
}: {
    card: Card;
    className?: string;
}) {
    const primary = card.channels[0];
    const extraChannels = Math.max(0, card.channels.length - 1);

    return (
        <Link
            href={card.url}
            className={cn(
                'group surface relative flex h-full flex-col gap-4 p-4 transition-[box-shadow,transform] duration-200 ease-out outline-none hover:-translate-y-0.5 hover:shadow-lift focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
        >
            <ArrowUpRight
                aria-hidden="true"
                className="absolute top-4 right-4 size-4 text-ink-soft opacity-0 transition-[opacity,transform] duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100"
            />

            <div className="flex items-center gap-3 pr-5">
                <ProfileAvatar
                    name={card.name}
                    src={card.avatar}
                    className="size-12 ring-[3px]"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1">
                        <h3 className="truncate font-medium tracking-tight">
                            {card.name}
                        </h3>
                        {card.is_claimed && (
                            <BadgeCheck
                                className="size-4 shrink-0 text-brand-ink"
                                aria-label="Verified"
                            />
                        )}
                    </div>
                    <p className="flex items-center gap-1.5 truncate font-mono text-xs text-muted-foreground">
                        {primary && (
                            <span className="truncate">
                                @{primary.username}
                            </span>
                        )}
                        {extraChannels > 0 && (
                            <span className="shrink-0 text-ink-soft">
                                +{extraChannels}
                            </span>
                        )}
                        {card.owner && (
                            <span className="min-w-0 truncate font-sans text-ink-soft">
                                · by {card.owner.name}
                            </span>
                        )}
                    </p>
                </div>
            </div>

            {card.hook ? (
                <p className="line-clamp-2 text-sm leading-relaxed text-pretty text-foreground/80">
                    {card.hook}
                </p>
            ) : (
                <p className="text-sm text-ink-soft">Profile on its way.</p>
            )}

            <div className="mt-auto flex items-end justify-between gap-2">
                <div className="flex min-w-0 flex-wrap gap-1.5">
                    {card.is_claimed ? (
                        <Badge variant="mint">Verified</Badge>
                    ) : (
                        <Badge
                            variant="outline"
                            className="text-muted-foreground"
                        >
                            Unclaimed
                        </Badge>
                    )}
                    {card.vibe_tags.slice(0, 2).map((tag, i) => (
                        <Badge
                            key={tag}
                            variant={TAG_TONES[i % TAG_TONES.length]}
                        >
                            {tag}
                        </Badge>
                    ))}
                </div>
                <span className="tabular flex shrink-0 items-center gap-1 font-mono text-[11px] text-muted-foreground">
                    <Users className="size-3" />
                    {formatCount(card.subscribers)}
                </span>
            </div>
        </Link>
    );
}
