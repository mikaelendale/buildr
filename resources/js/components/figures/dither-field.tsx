import { Dithering } from '@paper-design/shaders-react';
import type { DitheringProps } from '@paper-design/shaders-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type DitheringShape = NonNullable<DitheringProps['shape']>;

/**
 * Ordered-dither texture where the design used to reach for a gradient:
 * hero bands, profile covers, "working" states. Two pixel layers (a soft
 * wash and a brighter accent) fade into the canvas. The shader mounts after
 * hydration, idles off screen, and holds still under reduced motion; the CSS
 * wash underneath is what the server renders.
 */

/** The five profile tones, plus ink: a black-and-white print owners can pick. */
export type DitherTone = 'mint' | 'sky' | 'violet' | 'amber' | 'rose' | 'ink';

export const PALETTE: Record<
    DitherTone,
    { light: [string, string]; dark: [string, string]; wash: string }
> = {
    mint: {
        light: ['#9fe9d4', '#3cc29d'],
        dark: ['#14524a', '#43d3a8'],
        wash: 'var(--mint-soft)',
    },
    sky: {
        light: ['#b9d6fb', '#5b98ef'],
        dark: ['#173a72', '#4f8ff0'],
        wash: 'var(--sky-soft)',
    },
    violet: {
        light: ['#d6c9fb', '#9a7cf0'],
        dark: ['#33256b', '#8f72f2'],
        wash: 'var(--violet-soft)',
    },
    amber: {
        light: ['#f6dca0', '#e3a032'],
        dark: ['#5a3d10', '#e8a94a'],
        wash: 'var(--amber-soft)',
    },
    rose: {
        light: ['#f9c7d5', '#ec6a91'],
        dark: ['#5c1f33', '#ec7a9c'],
        wash: 'var(--rose-soft)',
    },
    ink: {
        light: ['#d6d6d1', '#8a8a84'],
        dark: ['#2e2e2b', '#9a9a93'],
        wash: 'var(--secondary)',
    },
};

const FADES = {
    bottom: 'linear-gradient(to bottom, #000 0%, #000 38%, transparent 100%)',
    // Calm under a header, full in the middle, gone before the content.
    band: 'linear-gradient(to bottom, transparent 0px, #000 120px, #000 46%, transparent 94%)',
    soft: 'radial-gradient(120% 90% at 50% 0%, #000 35%, transparent 80%)',
    none: undefined,
};

export function useDarkMode(): boolean {
    const [dark, setDark] = useState(false);

    useEffect(() => {
        const root = document.documentElement;
        const sync = () => setDark(root.classList.contains('dark'));
        sync();
        const observer = new MutationObserver(sync);
        observer.observe(root, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    return dark;
}

export default function DitherField({
    tone = 'mint',
    shape = 'warp',
    secondaryShape = 'simplex',
    seed = 0,
    speed = 0.25,
    size = 2,
    fade = 'bottom',
    className,
}: {
    tone?: DitherTone;
    shape?: DitheringShape;
    secondaryShape?: DitheringShape;
    /** Offsets the pattern so every profile gets its own field. */
    seed?: number;
    speed?: number;
    size?: number;
    fade?: keyof typeof FADES;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [mounted, setMounted] = useState(false);
    const [visible, setVisible] = useState(true);
    const [reduced, setReduced] = useState(false);
    const dark = useDarkMode();
    const palette = PALETTE[tone];
    const [soft, bright] = dark ? palette.dark : palette.light;

    useEffect(() => {
        setMounted(true);
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        const syncMotion = () => setReduced(media.matches);
        syncMotion();
        media.addEventListener('change', syncMotion);

        const observer = new IntersectionObserver(([entry]) =>
            setVisible(entry.isIntersecting),
        );

        if (ref.current) {
            observer.observe(ref.current);
        }

        return () => {
            media.removeEventListener('change', syncMotion);
            observer.disconnect();
        };
    }, []);

    const pace = reduced || !visible ? 0 : speed;
    const mask = FADES[fade];
    const frame = (seed % 997) * 1300;

    return (
        <div
            ref={ref}
            aria-hidden="true"
            className={cn('pointer-events-none overflow-hidden', className)}
            style={
                mask ? { maskImage: mask, WebkitMaskImage: mask } : undefined
            }
        >
            <div
                className="absolute inset-0"
                style={{
                    background: `radial-gradient(90% 120% at 30% 0%, ${palette.wash}, transparent 70%)`,
                }}
            />
            {mounted && (
                <div className="absolute inset-0 animate-[rise-in_900ms_var(--ease-out-soft)_both]">
                    <Dithering
                        className="absolute inset-0"
                        style={{ width: '100%', height: '100%' }}
                        colorBack="#00000000"
                        colorFront={soft}
                        shape={secondaryShape}
                        type="8x8"
                        size={size * 1.5}
                        scale={1.4}
                        speed={pace * 0.6}
                        frame={frame + 400}
                        minPixelRatio={1}
                    />
                    <Dithering
                        className="absolute inset-0"
                        style={{ width: '100%', height: '100%' }}
                        colorBack="#00000000"
                        colorFront={bright}
                        shape={shape}
                        type="4x4"
                        size={size}
                        scale={0.9}
                        rotation={(seed * 37) % 360}
                        speed={pace}
                        frame={frame}
                        minPixelRatio={1}
                    />
                </div>
            )}
        </div>
    );
}

/** Stable small number from a string (profile username → its own field). */
export function seedFrom(value: string): number {
    let hash = 0;

    for (let i = 0; i < value.length; i++) {
        hash = (hash * 31 + value.charCodeAt(i)) | 0;
    }

    return Math.abs(hash);
}

/** Tones a profile is seeded with (ink is only ever picked). */
export const TONES: DitherTone[] = ['mint', 'sky', 'violet', 'amber', 'rose'];
