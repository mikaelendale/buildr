import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * Hero figure: a channel feed on the left, the profile Buildr writes on the
 * right. One claim at a time lights up, and a wire with rounded elbows runs
 * from the post it came from to the line it supports. Pointing at a line
 * holds that claim; leaving resumes the loop from there.
 *
 * Rest pose (server render, reduced motion): every line written, the first
 * claim wired.
 */

const POSTS = [76, 126, 176, 226]; // bubble tops in the feed
const BUBBLE_H = 38;

type Claim = {
    key: string;
    label: string;
    post: number; // index into POSTS
    y: number; // row centre on the profile card
};

const CLAIMS: Claim[] = [
    { key: 'hook', label: 'Hook', post: 2, y: 128 },
    { key: 'era', label: 'Journey', post: 0, y: 176 },
    { key: 'project', label: 'Project', post: 3, y: 222 },
];

const FEED_RIGHT = 186;
const CARD_LEFT = 334;
const MID_X = 262;
const R = 10;

function wire(fromY: number, toY: number): string {
    if (Math.abs(toY - fromY) < 1) {
        return `M${FEED_RIGHT} ${fromY}H${CARD_LEFT}`;
    }

    const dir = toY > fromY ? 1 : -1;

    return [
        `M${FEED_RIGHT} ${fromY}`,
        `H${MID_X - R}`,
        `Q${MID_X} ${fromY} ${MID_X} ${fromY + R * dir}`,
        `V${toY - R * dir}`,
        `Q${MID_X} ${toY} ${MID_X + R} ${toY}`,
        `H${CARD_LEFT}`,
    ].join('');
}

const STEP_MS = 3000;

export default function SourceFigure({ className }: { className?: string }) {
    const [step, setStep] = useState(0);
    const [held, setHeld] = useState(false);
    const [visible, setVisible] = useState(false);
    const [reduced, setReduced] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        const sync = () => setReduced(media.matches);
        sync();
        media.addEventListener('change', sync);

        const observer = new IntersectionObserver(
            ([entry]) => setVisible(entry.isIntersecting),
            { threshold: 0.2 },
        );

        if (root.current) {
            observer.observe(root.current);
        }

        return () => {
            media.removeEventListener('change', sync);
            observer.disconnect();
        };
    }, []);

    useEffect(() => {
        if (!visible || held || reduced) {
            return;
        }

        const timer = window.setInterval(
            () => setStep((s) => (s + 1) % CLAIMS.length),
            STEP_MS,
        );

        return () => window.clearInterval(timer);
    }, [visible, held, reduced]);

    const current = CLAIMS[step];

    return (
        <div ref={root} className={cn('relative', className)}>
            <div className="flex h-4 items-center justify-between gap-3">
                <span className="eyebrow">
                    <span className="pointer-coarse:hidden">
                        Point at a line
                    </span>
                    <span className="hidden pointer-coarse:inline">
                        Tap a line
                    </span>
                </span>
                <span
                    className="tabular font-mono text-[11px] whitespace-nowrap text-muted-foreground"
                    aria-live="polite"
                >
                    {current.label} ← post {current.post + 1}
                </span>
            </div>

            <svg
                viewBox="0 0 560 300"
                className="hairline mt-3 w-full text-foreground"
                role="img"
                aria-label="A channel's posts on the left, each linked by a wire to the line of the profile it supports."
            >
                {/* Feed */}
                <rect
                    x="18"
                    y="22"
                    width="184"
                    height="262"
                    rx="18"
                    className="fill-border stroke-none"
                    opacity="0.55"
                    transform="translate(2 3)"
                />
                <rect
                    x="18"
                    y="22"
                    width="184"
                    height="262"
                    rx="18"
                    data-fill="face"
                />
                <circle cx="44" cy="48" r="10" data-fill="face" />
                <path d="M44 44.5v7M40.5 48h7" data-tone="dim" />
                <path d="M62 44h62" />
                <path d="M62 53h36" data-tone="dim" />
                <text
                    x="186"
                    y="52"
                    textAnchor="end"
                    className="fill-muted-foreground stroke-none font-mono text-[9px]"
                >
                    t.me
                </text>

                {POSTS.map((top, i) => {
                    const lit = i === current.post;

                    return (
                        <g
                            key={top}
                            className="transition-opacity duration-300"
                            opacity={lit ? 1 : 0.5}
                        >
                            <rect
                                x="34"
                                y={top}
                                width="152"
                                height={BUBBLE_H}
                                rx="11"
                                data-fill={lit ? 'face' : 'canvas'}
                                className={cn(
                                    'transition-[stroke] duration-300',
                                    lit && 'stroke-brand-ink',
                                )}
                            />
                            <path d={`M47 ${top + 14}h${i % 2 ? 96 : 110}`} />
                            <path
                                d={`M47 ${top + 25}h${i % 2 ? 58 : 76}`}
                                data-tone="dim"
                            />
                            <text
                                x="176"
                                y={top + 29}
                                textAnchor="end"
                                className="fill-muted-foreground stroke-none font-mono text-[8px]"
                            >
                                {i + 1}
                            </text>
                        </g>
                    );
                })}

                {/* Wires: every claim keeps its own path so retracting one
                    never cuts the next one short. */}
                {CLAIMS.map((claim, i) => {
                    const on = i === step;
                    const from = POSTS[claim.post] + BUBBLE_H / 2;

                    return (
                        <g key={claim.key}>
                            <path
                                d={wire(from, claim.y)}
                                pathLength={1}
                                strokeDasharray="1 1"
                                strokeDashoffset={on ? 0 : 1}
                                data-tone="accent"
                                style={{
                                    transition: on
                                        ? 'stroke-dashoffset 720ms var(--ease-out-soft) 260ms'
                                        : 'stroke-dashoffset 240ms ease-out',
                                }}
                            />
                            <circle
                                cx={FEED_RIGHT}
                                cy={from}
                                r="2.6"
                                data-fill="brand"
                                className="stroke-brand-ink transition-opacity duration-200"
                                opacity={on ? 1 : 0}
                            />
                        </g>
                    );
                })}

                {/* Profile card */}
                <rect
                    x={CARD_LEFT}
                    y="30"
                    width="208"
                    height="246"
                    rx="18"
                    className="fill-border stroke-none"
                    opacity="0.55"
                    transform="translate(2 3)"
                />
                <rect
                    x={CARD_LEFT}
                    y="30"
                    width="208"
                    height="246"
                    rx="18"
                    data-fill="face"
                />
                <circle cx="372" cy="70" r="17" data-fill="face" />
                <circle cx="372" cy="65" r="5.5" data-tone="dim" />
                <path d="M362 81.5c2.6-4.6 17.4-4.6 20 0" data-tone="dim" />
                <path d="M400 63h74" />
                <rect
                    x="400"
                    y="72"
                    width="44"
                    height="12"
                    rx="6"
                    data-fill="brand"
                    className="stroke-none"
                />
                <path d="M450 78h22" data-tone="dim" />

                {CLAIMS.map((claim, i) => {
                    const on = i === step;
                    const tone = on ? 'stroke-brand-ink' : undefined;

                    return (
                        <g
                            key={claim.key}
                            className="transition-opacity duration-300"
                            opacity={on ? 1 : 0.55}
                        >
                            {claim.key === 'hook' && (
                                <>
                                    <path
                                        d={`M354 ${claim.y - 6}h158`}
                                        className={tone}
                                    />
                                    <path
                                        d={`M354 ${claim.y + 6}h112`}
                                        className={tone}
                                    />
                                </>
                            )}
                            {claim.key === 'era' && (
                                <>
                                    <circle
                                        cx="358"
                                        cy={claim.y}
                                        r="3.5"
                                        data-fill="face"
                                        className={tone}
                                    />
                                    <path
                                        d={`M368 ${claim.y}h96`}
                                        className={tone}
                                    />
                                    <path
                                        d={`M470 ${claim.y}h40`}
                                        data-tone="dim"
                                    />
                                </>
                            )}
                            {claim.key === 'project' && (
                                <>
                                    <rect
                                        x="354"
                                        y={claim.y - 10}
                                        width="74"
                                        height="20"
                                        rx="10"
                                        data-fill="face"
                                        className={tone}
                                    />
                                    <path
                                        d={`M366 ${claim.y}h50`}
                                        data-tone="dim"
                                    />
                                    <rect
                                        x="434"
                                        y={claim.y - 10}
                                        width="58"
                                        height="20"
                                        rx="10"
                                        data-fill="face"
                                        data-tone="dim"
                                    />
                                </>
                            )}
                            <circle
                                cx={CARD_LEFT}
                                cy={claim.y}
                                r="2.6"
                                data-fill="brand"
                                className="stroke-brand-ink transition-opacity duration-200"
                                opacity={on ? 1 : 0}
                            />
                        </g>
                    );
                })}

                <path d="M354 252h60" data-tone="dim" />
                <path d="M420 252h30" data-tone="dim" />

                {/* Fixed hit bands per claim row, so a moving highlight can
                    never slide out from under the pointer. */}
                {CLAIMS.map((claim, i) => (
                    <rect
                        key={claim.key}
                        x={CARD_LEFT}
                        y={claim.y - 20}
                        width="208"
                        height="40"
                        className="cursor-pointer fill-transparent stroke-none"
                        onPointerEnter={() => {
                            setHeld(true);
                            setStep(i);
                        }}
                        onPointerLeave={(e) => {
                            if (e.pointerType === 'mouse') {
                                setHeld(false);
                            }
                        }}
                        onClick={() => {
                            setHeld(true);
                            setStep(i);
                        }}
                    />
                ))}
            </svg>
        </div>
    );
}
