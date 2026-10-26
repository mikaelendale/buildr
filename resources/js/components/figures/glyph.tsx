import { cn } from '@/lib/utils';

/**
 * Hairline glyphs (48×48): one stroke weight, faces filled with the card
 * colour, a soft offset shadow for depth, and a four-point sparkle. Parts
 * carry `data-m` (twinkle, lift, draw, nudge) and wake when the nearest
 * `.group` is hovered or focused; reduced motion keeps the rest pose.
 */
export type GlyphName =
    | 'feed'
    | 'claim'
    | 'profile'
    | 'search'
    | 'journey'
    | 'project'
    | 'quote'
    | 'clock'
    | 'empty'
    | 'send'
    | 'shield'
    | 'chart'
    | 'spark';

function Sparkle({ x, y, s = 3.6 }: { x: number; y: number; s?: number }) {
    const k = s * 0.22;

    return (
        <path
            data-m="twinkle"
            data-fill="ink"
            d={`M${x} ${y - s}C${x + k} ${y - k} ${x + k} ${y - k} ${x + s} ${y}C${x + k} ${y + k} ${x + k} ${y + k} ${x} ${y + s}C${x - k} ${y + k} ${x - k} ${y + k} ${x - s} ${y}C${x - k} ${y - k} ${x - k} ${y - k} ${x} ${y - s}Z`}
        />
    );
}

/** The same shape twice: a soft shadow two units down-right, then the face. */
function Solid({ d, m }: { d: string; m?: string }) {
    return (
        <g data-m={m}>
            <path
                d={d}
                transform="translate(1.6 1.8)"
                className="fill-border stroke-none"
                opacity="0.7"
            />
            <path d={d} data-fill="face" />
        </g>
    );
}

const rect = (x: number, y: number, w: number, h: number, r: number) =>
    `M${x + r} ${y}H${x + w - r}A${r} ${r} 0 0 1 ${x + w} ${y + r}V${y + h - r}A${r} ${r} 0 0 1 ${x + w - r} ${y + h}H${x + r}A${r} ${r} 0 0 1 ${x} ${y + h - r}V${y + r}A${r} ${r} 0 0 1 ${x + r} ${y}Z`;

const circle = (cx: number, cy: number, r: number) =>
    `M${cx - r} ${cy}A${r} ${r} 0 1 0 ${cx + r} ${cy}A${r} ${r} 0 1 0 ${cx - r} ${cy}Z`;

const GLYPHS: Record<GlyphName, React.ReactNode> = {
    feed: (
        <>
            <Solid d={rect(10, 7, 23, 32, 6)} />
            <path d={rect(14.5, 13, 12, 5.5, 2.75)} data-m="lift" />
            <path
                d={rect(14.5, 21, 14.5, 5.5, 2.75)}
                data-m="lift"
                data-d="1"
                data-tone="accent"
            />
            <path
                d={rect(14.5, 29, 9, 5, 2.5)}
                data-m="lift"
                data-d="2"
                data-tone="dim"
            />
            <Sparkle x={38} y={11} />
        </>
    ),
    claim: (
        <>
            <Solid d={rect(8, 14, 28, 21, 6)} />
            <path
                d="M15.5 24.5l3.6 3.6 7.4-7.6"
                pathLength={1}
                data-m="draw"
                data-tone="accent"
            />
            <path d="M14 30.5h5M21.5 30.5h3" data-tone="dim" />
            <Sparkle x={38.5} y={11.5} />
        </>
    ),
    profile: (
        <>
            <Solid d={rect(7, 12, 31, 24, 6)} />
            <path d={circle(16.5, 22, 4.2)} data-m="lift" />
            <path d="M24.5 20h8.5" />
            <path d="M24.5 24.5h5.5" data-tone="dim" />
            <path
                d="M12.5 30.5h20"
                data-tone="dim"
                pathLength={1}
                data-m="draw"
            />
            <Sparkle x={40} y={9.5} />
        </>
    ),
    search: (
        <>
            <Solid d={circle(20.5, 20.5, 10)} />
            <path d="M28 28l7.5 7.5" data-m="nudge" />
            <path d="M16 18.5h9" />
            <path d="M16 23h5.5" data-tone="dim" />
            <Sparkle x={38} y={10} />
        </>
    ),
    journey: (
        <>
            <path
                d="M8.5 34.5C16.5 34.5 16 19 24 19S31.5 10.5 39.5 10.5"
                pathLength={1}
                data-m="draw"
                data-tone="dim"
            />
            <Solid d={circle(8.5, 34.5, 3)} />
            <Solid d={circle(24, 19, 3)} m="lift" />
            <g data-tone="accent">
                <Solid d={circle(39.5, 10.5, 3)} m="lift" />
            </g>
            <Sparkle x={36} y={33} s={3.2} />
        </>
    ),
    project: (
        <>
            <Solid d="M24 9.5L36.5 16v14L24 36.5 11.5 30V16z" m="lift" />
            <path d="M11.5 16L24 22.5 36.5 16M24 22.5v14" data-tone="dim" />
            <Sparkle x={40} y={9} s={3.2} />
        </>
    ),
    quote: (
        <>
            <Solid d="M15 10.5h18a6 6 0 0 1 6 6v8a6 6 0 0 1-6 6H21l-6.5 5.5V30.3A6 6 0 0 1 9 24.5v-8a6 6 0 0 1 6-6z" />
            <path d="M17 18.5v3.5M21 18.5v3.5" data-m="lift" />
            <path d="M25.5 20.5h7" data-tone="dim" />
            <Sparkle x={41} y={9} s={3.2} />
        </>
    ),
    clock: (
        <>
            <Solid d={circle(22, 25, 11.5)} />
            <path d="M22 25v-6.5" data-m="twinkle" />
            <path d="M22 25l4.5 2.5" data-tone="accent" />
            <Sparkle x={37.5} y={11} />
        </>
    ),
    empty: (
        <>
            <path
                d={rect(9, 12, 30, 24, 6)}
                strokeDasharray="3 3.2"
                data-tone="dim"
            />
            <path d="M24 20.5v7M20.5 24h7" data-m="twinkle" />
            <Sparkle x={39} y={10} s={3.2} />
        </>
    ),
    send: (
        <>
            <Solid d="M9 23.5L37.5 12 31 36.5l-8-7.5z" m="nudge" />
            <path d="M23 29l14.5-17" data-tone="dim" />
            <Sparkle x={12} y={12} s={3.2} />
        </>
    ),
    shield: (
        <>
            <Solid d="M24 8.5l12 4.5v9.5c0 7.6-5.2 12.8-12 15.5-6.8-2.7-12-7.9-12-15.5V13z" />
            <path
                d="M19 23.5l3.5 3.5 6.5-7"
                pathLength={1}
                data-m="draw"
                data-tone="accent"
            />
            <Sparkle x={40} y={10} s={3.2} />
        </>
    ),
    chart: (
        <>
            <path d="M9.5 37.5h29" data-tone="dim" />
            <Solid d={rect(12, 27, 5.5, 10.5, 2)} />
            <Solid d={rect(21, 21, 5.5, 16.5, 2)} m="lift" />
            <g data-tone="accent">
                <Solid d={rect(30, 14, 5.5, 23.5, 2)} m="lift" />
            </g>
            <Sparkle x={39} y={9} s={3.2} />
        </>
    ),
    spark: (
        <>
            <Sparkle x={22} y={24} s={9} />
            <Sparkle x={35} y={13} s={3.6} />
        </>
    ),
};

export default function Glyph({
    name,
    className,
    title,
}: {
    name: GlyphName;
    className?: string;
    title?: string;
}) {
    return (
        <svg
            viewBox="0 0 48 48"
            role={title ? 'img' : undefined}
            aria-hidden={title ? undefined : true}
            aria-label={title}
            className={cn(
                'glyph hairline size-12 shrink-0 text-foreground/85',
                className,
            )}
        >
            {GLYPHS[name]}
        </svg>
    );
}
