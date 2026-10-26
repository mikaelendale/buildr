import { Link } from '@inertiajs/react';
import { LogoMark } from '@/components/brand/logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Sign-in frame: logo tile, a light headline, then the form seated on a
 * white card on the grey canvas.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background px-5 py-10">
            <div className="w-full max-w-[400px]">
                <div className="flex flex-col items-center gap-5 text-center">
                    <Link
                        href={home()}
                        className="rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <LogoMark className="size-11 drop-shadow-[0_1px_2px_oklch(0_0_0/0.08)]" />
                        <span className="sr-only">Buildr home</span>
                    </Link>

                    <div className="space-y-1.5">
                        <h1 className="text-[26px] leading-tight font-light tracking-[-0.03em] text-balance">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-sm text-pretty text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                </div>

                <div className="surface mt-8 p-6 sm:p-7">{children}</div>

                <p className="mt-6 text-center font-mono text-[11px] text-ink-soft">
                    buildr · find out who&apos;s behind the channel
                </p>
            </div>
        </div>
    );
}
