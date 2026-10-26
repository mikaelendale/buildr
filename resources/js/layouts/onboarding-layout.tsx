import { Link } from '@inertiajs/react';
import Logo from '@/components/brand/logo';

export default function OnboardingLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="min-h-svh bg-background">
            <header>
                <div className="mx-auto flex h-16 max-w-[720px] items-center justify-between px-5">
                    <Link
                        href="/"
                        aria-label="Buildr home"
                        className="rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <Logo />
                    </Link>
                    <Link
                        href="/dashboard"
                        className="rounded-full bg-card px-3 py-1.5 text-[13px] text-muted-foreground shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/80 transition-colors hover:text-foreground"
                    >
                        Save &amp; exit
                    </Link>
                </div>
            </header>
            <main className="mx-auto max-w-[720px] px-5 pt-8 pb-20 sm:pt-12">
                {children}
            </main>
        </div>
    );
}
