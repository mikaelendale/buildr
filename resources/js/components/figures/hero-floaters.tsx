import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { ProfileCard } from '@/types';

/**
 * Real profile pictures drifting in the hero's dither field: plain photos
 * (never dithered) in white-ringed circles, parked on the right so they
 * never sit under the headline. Each one floats on its own slow rhythm and
 * names itself on hover. Reduced motion keeps them still.
 */

// Hand-placed spots (percent of the band) and sizes; first ones are largest.
const SPOTS = [
    { x: 70, y: 42, size: 76, delay: 0 },
    { x: 86, y: 26, size: 60, delay: 1.2 },
    { x: 79, y: 70, size: 52, delay: 2.1 },
    { x: 93, y: 56, size: 64, delay: 0.6 },
    { x: 62, y: 20, size: 44, delay: 1.7 },
    { x: 96, y: 14, size: 40, delay: 2.6 },
    { x: 60, y: 66, size: 40, delay: 3.1 },
    { x: 89, y: 84, size: 36, delay: 1.4 },
];

export default function HeroFloaters({
    cards,
    className,
}: {
    cards: ProfileCard[];
    className?: string;
}) {
    const people = cards
        .filter((c) => c.avatar)
        .filter((c, i, all) => all.findIndex((o) => o.url === c.url) === i)
        .slice(0, SPOTS.length);

    if (people.length === 0) {
        return null;
    }

    return (
        <div className={cn('pointer-events-none absolute inset-0', className)}>
            {people.map((card, i) => {
                const spot = SPOTS[i];

                return (
                    <Link
                        key={card.url}
                        href={card.url}
                        aria-label={card.name}
                        className={cn(
                            'group pointer-events-auto absolute -translate-x-1/2 -translate-y-1/2 outline-none',
                            // Phones keep the four biggest, a bit smaller.
                            i >= 4 && 'hidden md:block',
                        )}
                        style={{ left: `${spot.x}%`, top: `${spot.y}%` }}
                    >
                        <span
                            className="block motion-safe:animate-[float-y_7s_ease-in-out_infinite]"
                            style={{
                                animationDelay: `-${spot.delay}s`,
                                animationDuration: `${6 + (i % 3)}s`,
                            }}
                        >
                            <img
                                src={card.avatar ?? ''}
                                alt=""
                                loading="lazy"
                                className="block rounded-full object-cover shadow-lift ring-[3px] ring-card transition-transform duration-200 ease-out group-hover:scale-[1.06] group-focus-visible:scale-[1.06] max-md:!size-12"
                                style={{
                                    width: spot.size,
                                    height: spot.size,
                                }}
                            />
                            <span className="pointer-events-none absolute top-full left-1/2 mt-2 -translate-x-1/2 translate-y-1 rounded-full bg-card px-2.5 py-1 text-xs font-medium whitespace-nowrap text-foreground opacity-0 shadow-soft ring-1 ring-border/70 transition-[opacity,transform] duration-150 group-hover:translate-y-0 group-hover:opacity-100 group-focus-visible:translate-y-0 group-focus-visible:opacity-100">
                                {card.name}
                            </span>
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}
