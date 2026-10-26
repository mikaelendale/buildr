import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';

/**
 * Centered message page (errors, removed, suspended): a hairline glyph, a
 * mono eyebrow, a light headline and whatever actions fit the situation.
 */
export default function StatusPage({
    glyph,
    eyebrow,
    title,
    children,
}: {
    glyph: GlyphName;
    eyebrow?: React.ReactNode;
    title: React.ReactNode;
    children?: React.ReactNode;
}) {
    return (
        <div className="group mx-auto flex max-w-md flex-col items-center px-5 pt-20 text-center sm:pt-28">
            <div className="surface flex size-20 items-center justify-center rounded-3xl">
                <Glyph name={glyph} className="size-12" />
            </div>
            {eyebrow && <div className="eyebrow mt-6">{eyebrow}</div>}
            <h1 className="mt-2 text-[28px] leading-tight font-light tracking-[-0.03em] text-balance">
                {title}
            </h1>
            <div className="mt-3 w-full text-pretty text-muted-foreground">
                {children}
            </div>
        </div>
    );
}
