import { Link } from '@inertiajs/react';
import { ImageDithering } from '@paper-design/shaders-react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import DitherField, {
    seedFrom,
    TONES,
    useDarkMode,
} from '@/components/figures/dither-field';
import type { DitherTone } from '@/components/figures/dither-field';

const SHAPES = ['simplex', 'warp', 'swirl', 'ripple'] as const;

/**
 * A profile's dither identity: tone and pattern derived from the username,
 * shared by the profile cover, the creator dashboard and the share card.
 * The owner can pick another tone (their share card color).
 */
export function ditherIdentity(
    seedKey: string,
    picked?: DitherTone | null,
): {
    seed: number;
    tone: DitherTone;
    shape: (typeof SHAPES)[number];
} {
    const seed = seedFrom(seedKey);

    return {
        seed,
        tone: picked ?? TONES[seed % TONES.length],
        shape: SHAPES[seed % SHAPES.length],
    };
}

/**
 * Ink for the halftone portrait. Light mode prints the dark parts of the
 * photo (inverted) like ink on paper; dark mode lets the highlights glow.
 */
const PORTRAIT: Record<
    DitherTone,
    { light: [string, string]; dark: [string, string] }
> = {
    mint: { light: ['#1f9a7a', '#8fe3c9'], dark: ['#43d3a8', '#b4f5df'] },
    sky: { light: ['#2f6fd6', '#a9cbf8'], dark: ['#5b98ef', '#c7dcfb'] },
    violet: { light: ['#6a4bdc', '#c6b5f8'], dark: ['#8f72f2', '#d7cbfb'] },
    amber: { light: ['#b26b0e', '#f3cf86'], dark: ['#e8a94a', '#f8dfae'] },
    rose: { light: ['#c2335f', '#f6b3c6'], dark: ['#ec7a9c', '#fad0dc'] },
    ink: { light: ['#1c1c1a', '#c4c4bd'], dark: ['#e6e6e2', '#8a8a84'] },
};

/**
 * WebGL only takes cross-origin images with CORS headers; avatars on the
 * storage bucket's domain come through /media on our own origin instead.
 */
function sameOrigin(src: string): string {
    if (typeof window === 'undefined') {
        return src;
    }

    const url = new URL(src, window.location.href);

    return url.origin === window.location.origin
        ? src
        : `/media${url.pathname}`;
}

// A soft vignette: fades toward the content (left), the page edge (right),
// under the header (top) and into the page (bottom). No hard edges.
const PORTRAIT_MASK =
    'linear-gradient(to right, transparent 0%, #000 34%, #000 64%, transparent 100%), linear-gradient(to bottom, transparent 0%, #000 16%, #000 46%, transparent 96%)';

/**
 * A person's dither identity drawn into a box: a halftone print of their
 * avatar in their tone (dark parts as ink in light mode, highlights glowing
 * in dark mode), or their seeded pattern field without a usable avatar.
 * Static, mounted after hydration; the box decides size and fades.
 */
export function PortraitDither({
    seedKey,
    tone: picked,
    avatar,
    className,
    style,
    size = 3,
}: {
    seedKey: string;
    tone?: DitherTone | null;
    avatar?: string | null;
    className?: string;
    style?: React.CSSProperties;
    size?: number;
}) {
    const { seed, tone, shape } = ditherIdentity(seedKey, picked);
    const dark = useDarkMode();
    const [mounted, setMounted] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => setMounted(true), []);

    // Probe the image first so a broken avatar falls back to the pattern.
    useEffect(() => {
        if (!avatar) {
            return;
        }

        const img = new Image();
        img.onerror = () => setFailed(true);
        img.src = avatar;
    }, [avatar]);

    if (!avatar || failed) {
        return (
            <DitherField
                className={className}
                tone={tone}
                shape={shape}
                secondaryShape="simplex"
                seed={seed}
                fade="none"
                speed={0}
                size={size - 0.5}
            />
        );
    }

    const [front, highlight] = dark
        ? PORTRAIT[tone].dark
        : PORTRAIT[tone].light;

    return (
        <div aria-hidden="true" className={className} style={style}>
            {mounted && (
                <div className="absolute inset-0 animate-[rise-in_900ms_var(--ease-out-soft)_both]">
                    <ImageDithering
                        className="absolute inset-0"
                        style={{ width: '100%', height: '100%' }}
                        image={sameOrigin(avatar)}
                        colorBack="#00000000"
                        colorFront={front}
                        colorHighlight={highlight}
                        originalColors={false}
                        inverted={!dark}
                        type={seed % 2 === 0 ? '8x8' : '4x4'}
                        size={size}
                        colorSteps={2}
                        fit="cover"
                        scale={1.05}
                        minPixelRatio={1}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * The one dither on a profile page, curated to its person: their portrait
 * dither set to the right of the header and fading into the canvas (left
 * toward the content, down toward the page).
 */
export default function ProfileCover({
    seedKey,
    tone: picked,
    avatar,
}: {
    seedKey: string;
    /** The owner's share card color, when they picked one. */
    tone?: DitherTone | null;
    avatar?: string | null;
}) {
    if (!avatar) {
        const { seed, tone, shape } = ditherIdentity(seedKey, picked);

        return (
            <DitherField
                className="absolute inset-x-0 top-0 h-[300px] sm:h-[360px]"
                tone={tone}
                shape={shape}
                secondaryShape="simplex"
                seed={seed}
                fade="band"
                speed={0}
                size={2.5}
            />
        );
    }

    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-x-0 top-0 h-[340px] overflow-hidden sm:h-[440px]"
        >
            <PortraitDither
                seedKey={seedKey}
                tone={picked}
                avatar={avatar}
                className="absolute top-0 right-0 h-full w-[88vw] overflow-hidden opacity-60 sm:right-[max(0px,calc((100vw-1080px)/2))] sm:w-[min(640px,62vw)] sm:opacity-100"
                style={{
                    maskImage: PORTRAIT_MASK,
                    WebkitMaskImage: PORTRAIT_MASK,
                    maskComposite: 'intersect',
                    WebkitMaskComposite: 'source-in',
                }}
            />
        </div>
    );
}

/** Back link that stays legible on top of the cover pixels. */
export function BackPill({ href = '/discover' }: { href?: string }) {
    return (
        <Link
            href={href}
            className="inline-flex h-8 items-center gap-1.5 rounded-full bg-card/85 px-3 text-sm text-muted-foreground shadow-[0_1px_2px_oklch(0_0_0/0.06)] ring-1 ring-border/70 backdrop-blur-sm transition-colors hover:text-foreground"
        >
            <ArrowLeft className="size-4" />
            Back
        </Link>
    );
}
