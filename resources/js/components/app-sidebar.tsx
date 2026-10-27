import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    Ban,
    Bot,
    ClipboardCheck,
    Coins,
    Flag,
    FolderKanban,
    LayoutGrid,
    Megaphone,
    Radio,
    ScrollText,
    Settings,
    Sparkles,
    Star,
    Tags,
    UserRound,
    Users,
} from 'lucide-react';
import { LogoMark } from '@/components/brand/logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const creatorNav: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    { title: 'Profile', href: '/dashboard/profile', icon: UserRound },
    { title: 'AI summary', href: '/dashboard/summary', icon: Sparkles },
    { title: 'Projects', href: '/dashboard/projects', icon: FolderKanban },
    { title: 'Channels', href: '/dashboard/channels', icon: Radio },
    { title: 'Settings', href: '/dashboard/settings', icon: Settings },
];

const adminNav: NavItem[] = [
    { title: 'Overview', href: '/admin', icon: LayoutGrid },
    { title: 'Review queue', href: '/admin/review', icon: ClipboardCheck },
    { title: 'Channels', href: '/admin/channels', icon: Radio },
    { title: 'Creators', href: '/admin/creators', icon: Users },
    { title: 'Claims & disputes', href: '/admin/claims', icon: BadgeCheck },
    { title: 'Reports', href: '/admin/reports', icon: Flag },
    { title: 'Announcements', href: '/admin/announcements', icon: Megaphone },
    { title: 'Jobs', href: '/admin/jobs', icon: Activity },
    { title: 'AI costs', href: '/admin/ai-costs', icon: Coins },
    { title: 'Featured', href: '/admin/featured', icon: Star },
    { title: 'Tags', href: '/admin/tags', icon: Tags },
    { title: 'Blocklist', href: '/admin/blocklist', icon: Ban },
    { title: 'Audit log', href: '/admin/audit', icon: ScrollText },
];

export function AppSidebar() {
    const { auth, telegramBot } = usePage().props;
    const isAdmin = auth.user?.is_admin ?? false;
    const home = isAdmin ? '/admin' : '/dashboard';

    // "View my page" / "View site" live in the top bar of every page.
    const footer: NavItem[] = isAdmin
        ? []
        : telegramBot
          ? [
                {
                    title: 'Open the bot',
                    href: `https://t.me/${telegramBot}`,
                    icon: Bot,
                },
            ]
          : [];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home} prefetch>
                                {/* Wrapped: the menu button forces direct svg children to size-4. */}
                                <span className="flex size-8 shrink-0 items-center justify-center">
                                    <LogoMark className="size-8 drop-shadow-[0_1px_1px_oklch(0_0_0/0.06)]" />
                                </span>
                                <div className="ml-1 grid flex-1 text-left text-sm">
                                    <span className="truncate leading-tight font-medium tracking-[-0.01em] text-foreground">
                                        Buildr
                                    </span>
                                    <span className="truncate font-mono text-[11px] text-muted-foreground">
                                        {isAdmin ? 'admin' : 'creator studio'}
                                    </span>
                                </div>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={isAdmin ? adminNav : creatorNav}
                    label={isAdmin ? 'Admin' : 'Your page'}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footer} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
