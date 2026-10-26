import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    Globe,
    Loader2,
    PenLine,
    Plus,
    Settings2,
    Star,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import {
    ChannelProgressInline,
    useBuildingChannels,
} from '@/components/creator/channel-progress';
import type { ChannelProgress } from '@/components/creator/channel-progress';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { formatCount } from '@/lib/format';
import { requestJson } from '@/lib/http';
import type { ChannelChip } from '@/types';

type Channel = ChannelChip & {
    id: number;
    url: string;
    availability: string;
    last_scraped_at: string | null;
    page: {
        slug: string | null;
        /** A free short link from the username, when there is one. */
        suggested_slug: string | null;
        visible: boolean;
        show_owner: boolean;
        live: boolean;
    };
};

type Props = {
    channels: Channel[];
    claims: { id: number; username: string; code: string; purpose: string }[];
    maxChannels: number;
    building?: ChannelProgress[];
    profileUrl: string;
    profileLive: boolean;
};

const bareUrl = (url: string) => url.replace(/^https?:\/\//, '');

export default function ChannelsPage({
    channels,
    claims,
    maxChannels,
    building: initialBuilding = [],
    profileLive,
}: Props) {
    // Live progress for channels still being read; rows show it inline.
    const building = useBuildingChannels(initialBuilding, [
        'building',
        'channels',
    ]);
    const progressFor = (id: number) =>
        building.find((b) => b.channel?.id === id);
    const addForm = useForm({ username: '' });
    const [renaming, setRenaming] = useState<Channel | null>(null);
    const [removing, setRemoving] = useState<Channel | null>(null);
    const [editingPage, setEditingPage] = useState<Channel | null>(null);

    return (
        <PageContainer
            title="Channels"
            description={`Verify up to ${maxChannels} channels. Each one gets its own page; your profile links to all of them.`}
        >
            {channels.length === 0 ? (
                <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-5">
                    <Glyph name="feed" className="size-10" />
                    <p className="text-sm text-muted-foreground">
                        No verified channels yet. Add one below.
                    </p>
                </div>
            ) : (
                <ul className="surface divide-y divide-border/70 overflow-hidden">
                    {channels.map((channel) => {
                        const progress = progressFor(channel.id);

                        return (
                            <li
                                key={channel.id}
                                className="flex flex-wrap items-center gap-3 p-4 sm:px-5"
                            >
                                <ProfileAvatar
                                    name={channel.title}
                                    src={channel.avatar}
                                    className="size-10"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-medium tracking-tight">
                                            {channel.title}
                                        </span>
                                        {channel.is_primary && (
                                            <Badge variant="mint">Main</Badge>
                                        )}
                                        {progress && !progress.ready && (
                                            <Badge variant="sky">
                                                Being added
                                            </Badge>
                                        )}
                                        {channel.availability !== 'ok' && (
                                            <Badge variant="amber">
                                                {channel.availability.replace(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                        @{channel.username} ·{' '}
                                        <span className="tabular">
                                            {formatCount(channel.subscribers)}
                                        </span>{' '}
                                        subscribers
                                    </p>
                                </div>
                                <div className="-mr-2 flex flex-wrap gap-0.5">
                                    {!channel.is_primary && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `/dashboard/channels/${channel.id}/primary`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Star />
                                            Make main
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setRenaming(channel)}
                                    >
                                        <PenLine />
                                        Renamed?
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-rose hover:bg-rose-soft hover:text-rose"
                                        onClick={() => setRemoving(channel)}
                                    >
                                        <Trash2 />
                                        Remove
                                    </Button>
                                </div>
                                <PageLine
                                    channel={channel}
                                    profileLive={profileLive}
                                    onEdit={() => setEditingPage(channel)}
                                />
                                {progress && (
                                    <ChannelProgressInline
                                        progress={progress}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {claims.length > 0 && (
                <section>
                    <h2 className="eyebrow mb-2">Waiting for verification</h2>
                    <ul className="surface divide-y divide-border/70 overflow-hidden">
                        {claims.map((claim) => (
                            <li key={claim.id}>
                                <Link
                                    href={`/claims/${claim.id}`}
                                    className="group flex items-center gap-3 px-4 py-3.5 text-sm transition-colors hover:bg-background/60"
                                >
                                    <span className="size-2 shrink-0 animate-pulse rounded-full bg-amber motion-reduce:animate-none" />
                                    <span className="min-w-0 flex-1">
                                        <span className="font-mono">
                                            @{claim.username}
                                        </span>{' '}
                                        <Badge
                                            variant="outline"
                                            className="ml-1 text-muted-foreground"
                                        >
                                            {claim.purpose}
                                        </Badge>{' '}
                                        <code className="ml-1 rounded-md bg-secondary px-1.5 py-0.5 font-mono text-xs">
                                            {claim.code}
                                        </code>
                                    </span>
                                    <ArrowRight className="size-4 text-ink-soft transition-transform duration-150 group-hover:translate-x-0.5" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {channels.length < maxChannels && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        addForm.post('/claims');
                    }}
                    className="surface space-y-3 p-5"
                >
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 className="eyebrow">Add another channel</h2>
                        <span className="tabular font-mono text-[11px] text-muted-foreground">
                            {channels.length}/{maxChannels}
                        </span>
                    </div>
                    <div className="flex gap-2">
                        <Input
                            className="font-mono text-sm"
                            value={addForm.data.username}
                            onChange={(e) =>
                                addForm.setData('username', e.target.value)
                            }
                            placeholder="@channel or t.me link"
                        />
                        <Button
                            type="submit"
                            className="h-10"
                            disabled={addForm.processing}
                        >
                            <Plus />
                            Add
                        </Button>
                    </div>
                    <InputError message={addForm.errors.username} />
                </form>
            )}

            {renaming && (
                <RenameDialog
                    channel={renaming}
                    onClose={() => setRenaming(null)}
                />
            )}
            {removing && (
                <RemoveDialog
                    channel={removing}
                    onClose={() => setRemoving(null)}
                />
            )}
            {editingPage && (
                <PageDialog
                    channel={editingPage}
                    onClose={() => setEditingPage(null)}
                />
            )}
        </PageContainer>
    );
}

/**
 * The channel's own page: where it lives, whether it's public, and whether
 * it names you.
 */
function PageLine({
    channel,
    profileLive,
    onEdit,
}: {
    channel: Channel;
    profileLive: boolean;
    onEdit: () => void;
}) {
    const { page } = channel;
    const state = !page.visible
        ? { label: 'Hidden', variant: 'outline' as const }
        : !profileLive
          ? { label: 'Goes live with your profile', variant: 'amber' as const }
          : null;

    return (
        <div className="flex basis-full flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-secondary/70 py-1.5 pr-1.5 pl-3 text-xs sm:ml-[52px]">
            <Globe className="size-3.5 shrink-0 text-ink-soft" />
            <a
                href={channel.url}
                target="_blank"
                rel="noopener"
                className="inline-flex min-w-0 items-center gap-0.5 truncate font-mono text-foreground/80 transition-colors hover:text-foreground"
            >
                <span className="truncate">{bareUrl(channel.url)}</span>
                <ArrowUpRight className="size-3 shrink-0" />
            </a>
            {state && (
                <Badge
                    variant={state.variant}
                    className={
                        state.variant === 'outline'
                            ? 'text-muted-foreground'
                            : undefined
                    }
                >
                    {state.label}
                </Badge>
            )}
            {!page.show_owner && (
                <Badge variant="violet">Without your name</Badge>
            )}
            <Button
                size="sm"
                variant="ghost"
                className="ml-auto h-7 px-2.5 text-xs"
                onClick={onEdit}
            >
                <Settings2 />
                Page settings
            </Button>
        </div>
    );
}

function PageDialog({
    channel,
    onClose,
}: {
    channel: Channel;
    onClose: () => void;
}) {
    const initialSlug = channel.page.slug ?? '';
    const form = useForm({
        slug: initialSlug,
        visible: channel.page.visible,
        show_owner: channel.page.show_owner,
    });
    const [check, setCheck] = useState<{
        checking: boolean;
        available: boolean | null;
        message: string | null;
    }>({ checking: false, available: null, message: null });
    const host = new URL(channel.url).host;
    const slug = form.data.slug.trim();

    useEffect(() => {
        if (slug === initialSlug || slug.length < 3) {
            setCheck({ checking: false, available: null, message: null });

            return;
        }

        setCheck((c) => ({ ...c, checking: true }));

        const timer = window.setTimeout(async () => {
            const { data } = await requestJson<{
                available: boolean;
                message: string | null;
            }>(
                `/api/slug-check?slug=${encodeURIComponent(slug)}&channel=${channel.id}`,
            );
            setCheck({
                checking: false,
                available: data.available,
                message: data.message,
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [slug, initialSlug, channel.id]);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>@{channel.username}&apos;s page</DialogTitle>
                    <DialogDescription>
                        Every channel has its own page. Give it a short link, or
                        keep it at /c/{channel.username}.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/dashboard/channels/${channel.id}/page`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                    className="space-y-5"
                >
                    <div className="space-y-2">
                        <label
                            htmlFor="channel-slug"
                            className="text-sm font-medium"
                        >
                            Short link
                        </label>
                        <div className="flex h-10 items-center rounded-xl border border-input bg-card shadow-[0_1px_2px_oklch(0_0_0/0.03)] transition-[box-shadow,border-color] focus-within:border-ring focus-within:ring-4 focus-within:ring-ring/25">
                            <span className="pl-3.5 font-mono text-xs whitespace-nowrap text-ink-soft">
                                {host}/
                            </span>
                            <input
                                id="channel-slug"
                                value={form.data.slug}
                                onChange={(e) =>
                                    form.setData(
                                        'slug',
                                        e.target.value
                                            .toLowerCase()
                                            .replace(/[^a-z0-9-]/g, ''),
                                    )
                                }
                                placeholder={`c/${channel.username}`}
                                maxLength={30}
                                className="h-full w-full min-w-0 bg-transparent px-1 font-mono text-sm outline-none placeholder:text-ink-soft"
                                autoFocus
                            />
                            <span className="flex pr-3">
                                {check.checking && (
                                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                                )}
                                {!check.checking &&
                                    check.available === true && (
                                        <span className="flex size-5 items-center justify-center rounded-full bg-mint-soft">
                                            <Check className="size-3 text-mint" />
                                        </span>
                                    )}
                                {!check.checking &&
                                    check.available === false && (
                                        <span className="flex size-5 items-center justify-center rounded-full bg-rose-soft">
                                            <X className="size-3 text-rose" />
                                        </span>
                                    )}
                            </span>
                        </div>
                        <InputError
                            message={
                                form.errors.slug ??
                                (check.available === false
                                    ? (check.message ?? undefined)
                                    : undefined)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {slug === '' ? (
                                <>
                                    Empty keeps{' '}
                                    <span className="font-mono">
                                        {host}/c/{channel.username}
                                    </span>
                                    .
                                    {channel.page.suggested_slug && (
                                        <>
                                            {' '}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'slug',
                                                        channel.page
                                                            .suggested_slug ??
                                                            '',
                                                    )
                                                }
                                                className="font-medium text-foreground underline-offset-2 hover:underline"
                                            >
                                                Use /
                                                {channel.page.suggested_slug}
                                            </button>
                                        </>
                                    )}
                                </>
                            ) : (
                                <>
                                    The old /c/{channel.username} link keeps
                                    working and forwards here.
                                </>
                            )}
                        </p>
                    </div>

                    <div className="divide-y divide-border/70 rounded-2xl ring-1 ring-border/80">
                        <label className="flex cursor-pointer items-start gap-3 p-4">
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium">
                                    Public page
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    Off takes the page offline and out of
                                    search. You can still preview it.
                                </span>
                            </span>
                            <Switch
                                checked={form.data.visible}
                                onCheckedChange={(v) =>
                                    form.setData('visible', v)
                                }
                                aria-label="Public page"
                            />
                        </label>
                        <label className="flex cursor-pointer items-start gap-3 p-4">
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium">
                                    Show me as the owner
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    Off: the page stays verified but
                                    doesn&apos;t name you, and your profile
                                    doesn&apos;t list it.
                                </span>
                            </span>
                            <Switch
                                checked={form.data.show_owner}
                                onCheckedChange={(v) =>
                                    form.setData('show_owner', v)
                                }
                                aria-label="Show me as the owner"
                            />
                        </label>
                    </div>

                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                check.checking ||
                                check.available === false ||
                                (slug.length > 0 && slug.length < 3)
                            }
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RenameDialog({
    channel,
    onClose,
}: {
    channel: Channel;
    onClose: () => void;
}) {
    const form = useForm({ username: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Changed @{channel.username}&apos;s handle?
                    </DialogTitle>
                    <DialogDescription>
                        Enter the new handle. You&apos;ll get a code to put in
                        the channel description, and we&apos;ll check its posts
                        match so all your data moves over.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/dashboard/channels/${channel.id}/rename`);
                    }}
                    className="space-y-3"
                >
                    <Input
                        value={form.data.username}
                        onChange={(e) =>
                            form.setData('username', e.target.value)
                        }
                        placeholder="@newhandle"
                        autoFocus
                    />
                    <InputError message={form.errors.username} />
                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Get a code
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RemoveDialog({
    channel,
    onClose,
}: {
    channel: Channel;
    onClose: () => void;
}) {
    const form = useForm({ confirm: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove @{channel.username}?</DialogTitle>
                    <DialogDescription>
                        Its posts, summaries and projects are deleted and the
                        channel is blocklisted, so we never read it again. This
                        can&apos;t be undone.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.delete(`/dashboard/channels/${channel.id}`);
                    }}
                    className="space-y-3"
                >
                    <Input
                        value={form.data.confirm}
                        onChange={(e) =>
                            form.setData('confirm', e.target.value)
                        }
                        placeholder={`Type ${channel.username} to confirm`}
                    />
                    <InputError message={form.errors.confirm} />
                    <DialogFooter>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.confirm !== channel.username
                            }
                        >
                            Remove channel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

ChannelsPage.layout = {
    breadcrumbs: [{ title: 'Channels', href: '/dashboard/channels' }],
};
