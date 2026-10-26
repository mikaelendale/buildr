import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Menu, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import Logo from '@/components/brand/logo';
import SocialIcon from '@/components/profile/social-icon';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

const NAV = [
    { href: '/discover', label: 'Discover' },
    { href: '/search', label: 'Search' },
    { href: '/claim', label: 'Claim your page' },
];

function useScrolled(offset = 8): boolean {
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const sync = () => setScrolled(window.scrollY > offset);
        sync();
        window.addEventListener('scroll', sync, { passive: true });

        return () => window.removeEventListener('scroll', sync);
    }, [offset]);

    return scrolled;
}

export default function PublicLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage();
    const { auth, supportTelegram, maker } = page.props;
    const [open, setOpen] = useState(false);
    const scrolled = useScrolled();
    // Unmatched URLs render the error page without shared props (no auth).
    const user = auth?.user;
    const accountHref = user
        ? user.is_admin
            ? '/admin'
            : '/dashboard'
        : '/login';
    const accountLabel = user ? 'Dashboard' : 'Log in';

    return (
        <div className="relative flex min-h-svh flex-col bg-background">
            {/* Transparent until scrolled, so page bands (dither covers) run behind it. */}
            <header
                className={cn(
                    'sticky top-0 z-40 border-b transition-[background-color,border-color] duration-200',
                    scrolled
                        ? 'border-border/70 bg-background/80 backdrop-blur-md'
                        : 'border-transparent bg-transparent',
                )}
            >
                <div className="mx-auto flex h-16 w-full max-w-[1080px] items-center gap-6 px-5">
                    <Link
                        href="/"
                        className="shrink-0 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        aria-label="Buildr home"
                    >
                        <Logo />
                    </Link>

                    <nav className="hidden items-center gap-1 md:flex">
                        {NAV.map((item) => {
                            const current = page.url.startsWith(item.href);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'rounded-full px-3 py-1.5 text-sm transition-colors',
                                        current
                                            ? 'bg-card text-foreground shadow-[0_1px_2px_oklch(0_0_0/0.06)] ring-1 ring-border/70'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="ml-auto flex items-center gap-1.5">
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            className="md:hidden"
                            aria-label="Search"
                        >
                            <Link href="/search">
                                <Search />
                            </Link>
                        </Button>
                        <Button
                            asChild
                            size="sm"
                            className="hidden md:inline-flex"
                        >
                            <Link href={accountHref}>{accountLabel}</Link>
                        </Button>

                        <Sheet open={open} onOpenChange={setOpen}>
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="md:hidden"
                                    aria-label="Menu"
                                >
                                    <Menu />
                                </Button>
                            </SheetTrigger>
                            <SheetContent
                                side="right"
                                className="w-72 bg-background"
                            >
                                <SheetHeader>
                                    <SheetTitle>
                                        <Logo />
                                    </SheetTitle>
                                </SheetHeader>
                                <nav className="flex flex-col gap-1 px-3">
                                    {NAV.map((item) => (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            onClick={() => setOpen(false)}
                                            className="rounded-xl px-3 py-2.5 text-base hover:bg-card"
                                        >
                                            {item.label}
                                        </Link>
                                    ))}
                                    <Button
                                        asChild
                                        className="mt-3"
                                        onClick={() => setOpen(false)}
                                    >
                                        <Link href={accountHref}>
                                            {accountLabel}
                                        </Link>
                                    </Button>
                                </nav>
                            </SheetContent>
                        </Sheet>
                    </div>
                </div>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="mt-24">
                <div className="mx-auto w-full max-w-[1080px] px-5">
                    <div className="grid gap-10 border-t border-border/70 py-12 sm:grid-cols-[1.2fr_1fr]">
                        <div className="space-y-3">
                            <Logo />
                            <p className="max-w-xs text-sm text-pretty text-muted-foreground">
                                Find out who&apos;s behind the channel. Profiles
                                are written from public posts, and every line
                                links to its source.
                            </p>
                        </div>
                        <nav className="grid grid-cols-2 gap-x-8 gap-y-2.5 text-sm text-muted-foreground">
                            {[
                                ['/discover', 'Discover'],
                                ['/about', 'About'],
                                ['/claim', 'Claim your page'],
                                ['/terms', 'Terms'],
                                ['/remove', 'Remove my channel'],
                                ['/privacy', 'Privacy'],
                                ['/takedown-policy', 'Takedown policy'],
                            ].map(([href, label]) => (
                                <Link
                                    key={href}
                                    href={href}
                                    className="w-fit transition-colors hover:text-foreground"
                                >
                                    {label}
                                </Link>
                            ))}
                            {supportTelegram && (
                                <a
                                    href={`https://t.me/${supportTelegram}`}
                                    className="flex w-fit items-center gap-1 transition-colors hover:text-foreground"
                                    rel="noopener"
                                >
                                    Contact
                                    <ArrowUpRight className="size-3.5" />
                                </a>
                            )}
                        </nav>
                    </div>
                    <div className="flex flex-col gap-4 border-t border-border/70 py-6 sm:flex-row sm:items-center sm:justify-between">
                        {maker && (
                        <p className="text-sm text-muted-foreground">
                            Made with{' '}
                            <span role="img" aria-label="love">
                                ❤️
                            </span>{' '}
                            by{' '}
                            <a
                                href={maker.url}
                                target="_blank"
                                rel="noopener"
                                className="font-medium text-foreground underline-offset-4 hover:underline"
                            >
                                @{maker.handle}
                            </a>
                        </p>
                        )}
                        <div className="flex items-center gap-1">
                            {maker?.links.map((link) => (
                                <a
                                    key={link.url + link.platform}
                                    href={link.url}
                                    target="_blank"
                                    rel="me noopener"
                                    aria-label={link.label}
                                    title={link.label}
                                    className="flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-card hover:text-foreground hover:shadow-[0_1px_2px_oklch(0_0_0/0.06)]"
                                >
                                    <SocialIcon
                                        platform={link.platform}
                                        className="size-4"
                                    />
                                </a>
                            ))}
                        </div>
                    </div>
                    <div className="flex flex-col gap-1 pb-10 font-mono text-[11px] text-ink-soft sm:flex-row sm:justify-between">
                        <span>© {new Date().getFullYear()} Buildr</span>
                        <span>Not affiliated with Telegram.</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
