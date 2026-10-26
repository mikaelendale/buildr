import { usePage } from '@inertiajs/react';
import { ArrowUpRight, Moon, Sun } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The top row of the inset page panel. Same 56px as the sidebar's logo row
 * (8px panel margin + 56 = 64), so the two read as one frame.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';
    const page = auth.user?.is_admin
        ? { href: '/', label: 'View site' }
        : auth.user?.creator_slug
          ? { href: `/${auth.user.creator_slug}`, label: 'View my page' }
          : null;

    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b border-border/60 bg-background/85 px-3 backdrop-blur-md transition-[height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-4">
            <SidebarTrigger className="text-muted-foreground" />
            <Separator orientation="vertical" className="mr-1 !h-4" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />

            <div className="ml-auto flex items-center gap-1">
                {page && (
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="hidden sm:inline-flex"
                    >
                        <a href={page.href} target="_blank" rel="noopener">
                            {page.label}
                            <ArrowUpRight />
                        </a>
                    </Button>
                )}
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={dark ? 'Use light mode' : 'Use dark mode'}
                    onClick={() => updateAppearance(dark ? 'light' : 'dark')}
                >
                    {dark ? <Sun /> : <Moon />}
                </Button>
            </div>
        </header>
    );
}
