import { Link, router } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    EmptyState,
    NoteAction,
    postAction,
    Tray,
} from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { formatCount, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type FlagItem = { key: string; label: string };

type ProfileState = 'ready' | 'failed' | 'writing' | 'none';

type BulkAction =
    | 'approve_creators'
    | 'publish_channels'
    | 'regenerate'
    | 'approve_summaries';

type Props = {
    creators: {
        id: number;
        name: string;
        slug: string;
        submitted_at: string | null;
        channels: string[];
        profile: ProfileState;
        flags: FlagItem[];
    }[];
    channels: {
        id: number;
        username: string;
        title: string;
        subscribers: number | null;
        processed_at: string | null;
        flags: FlagItem[];
    }[];
    fieldChanges: {
        id: number;
        name: string;
        slug: string;
        changes: { field: string; old: string | null; new: string | null }[];
    }[];
    failed: {
        summary_id: number;
        channel_id: number;
        username: string | null;
        version: number;
        created_at: string | null;
        creator_id: number | null;
        missing: string[];
        dropped: number;
        has_live: boolean;
    }[];
};

const PROFILE: Record<
    ProfileState,
    { label: string; variant: 'mint' | 'rose' | 'amber' | 'outline' }
> = {
    ready: { label: 'Profile ready', variant: 'mint' },
    failed: { label: 'Profile failed check', variant: 'rose' },
    writing: { label: 'Still writing', variant: 'amber' },
    none: { label: 'No profile', variant: 'outline' },
};

function Flags({ flags }: { flags: FlagItem[] }) {
    if (flags.length === 0) return null;

    return (
        <div className="mt-2 flex flex-wrap gap-1">
            {flags.map((f) => (
                <Badge key={f.key + f.label} variant="amber">
                    <Flag />
                    {f.label}
                </Badge>
            ))}
        </div>
    );
}

/** Which rows of one list are ticked; resets when the list changes. */
function useSelection(ids: number[]) {
    const [picked, setPicked] = useState<number[]>([]);
    const key = ids.join(',');

    useEffect(() => setPicked([]), [key]);

    const all = ids.length > 0 && picked.length === ids.length;

    return {
        picked,
        has: (id: number) => picked.includes(id),
        toggle: (id: number) =>
            setPicked((p) =>
                p.includes(id) ? p.filter((x) => x !== id) : [...p, id],
            ),
        all,
        some: picked.length > 0 && !all,
        toggleAll: () => setPicked(all ? [] : ids),
        clear: () => setPicked([]),
    };
}

/**
 * A button that asks once: the first click arms it ("Click again to …"),
 * the second within a few seconds runs it. For actions that message people.
 */
function ConfirmButton({
    label,
    confirm,
    onConfirm,
    disabled,
    variant = 'default',
}: {
    label: string;
    confirm: string;
    onConfirm: () => void;
    disabled?: boolean;
    variant?: 'default' | 'outline';
}) {
    const [armed, setArmed] = useState(false);

    useEffect(() => {
        if (!armed) return;

        const timer = window.setTimeout(() => setArmed(false), 4000);

        return () => window.clearTimeout(timer);
    }, [armed]);

    return (
        <Button
            size="sm"
            variant={armed ? 'default' : variant}
            disabled={disabled}
            className={cn(armed && 'bg-rose text-white hover:bg-rose/90')}
            onClick={() => {
                if (armed) {
                    setArmed(false);
                    onConfirm();
                } else {
                    setArmed(true);
                }
            }}
        >
            {armed ? confirm : label}
        </Button>
    );
}

/** Select-all checkbox, count, and the bulk buttons for one list. */
function BulkBar({
    selection,
    count,
    children,
}: {
    selection: ReturnType<typeof useSelection>;
    count: number;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-border/70 px-4 py-2.5">
            <label className="mr-auto flex cursor-pointer items-center gap-2.5 text-xs text-muted-foreground select-none">
                <Checkbox
                    checked={
                        selection.all
                            ? true
                            : selection.some
                              ? 'indeterminate'
                              : false
                    }
                    onCheckedChange={selection.toggleAll}
                    aria-label="Select all"
                />
                {selection.picked.length > 0
                    ? `${selection.picked.length} of ${count} selected`
                    : `Select all ${count}`}
            </label>
            {children}
        </div>
    );
}

const row = 'flex flex-wrap items-center gap-3 px-4 py-3.5';

export default function ReviewQueue({
    creators,
    channels,
    fieldChanges,
    failed,
}: Props) {
    const [busy, setBusy] = useState<BulkAction | null>(null);
    const creatorSel = useSelection(creators.map((c) => c.id));
    const channelSel = useSelection(channels.map((c) => c.id));
    const failedSel = useSelection(failed.map((f) => f.summary_id));

    const bulk = (action: BulkAction, ids: number[], done: () => void) =>
        router.post(
            '/admin/review/bulk',
            { action, ids },
            {
                preserveScroll: true,
                onStart: () => setBusy(action),
                onFinish: () => setBusy(null),
                onSuccess: done,
            },
        );

    const failedChannels = (summaryIds: number[]) =>
        failed
            .filter((f) => summaryIds.includes(f.summary_id))
            .map((f) => f.channel_id);

    const empty =
        creators.length +
            channels.length +
            fieldChanges.length +
            failed.length ===
        0;

    return (
        <PageContainer
            title="Review queue"
            description="Oldest first. Tick rows to act on many at once. Approving and publishing message the creator; everything lands in the audit log."
            wide
        >
            {empty && (
                <div className="surface">
                    <EmptyState glyph="spark">
                        Nothing waiting. Every submission has been handled.
                    </EmptyState>
                </div>
            )}

            {creators.length > 0 && (
                <Tray label="Creator submissions" meta={creators.length}>
                    <BulkBar selection={creatorSel} count={creators.length}>
                        <ConfirmButton
                            label="Approve selected"
                            confirm={`Click again to approve ${creatorSel.picked.length}`}
                            disabled={
                                creatorSel.picked.length === 0 || busy !== null
                            }
                            onConfirm={() =>
                                bulk(
                                    'approve_creators',
                                    creatorSel.picked,
                                    creatorSel.clear,
                                )
                            }
                        />
                    </BulkBar>
                    <ul className="divide-y divide-border/70">
                        {creators.map((c) => (
                            <li key={c.id} className={row}>
                                <Checkbox
                                    checked={creatorSel.has(c.id)}
                                    onCheckedChange={() =>
                                        creatorSel.toggle(c.id)
                                    }
                                    aria-label={`Select ${c.name}`}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={`/admin/review/${c.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {c.name}
                                        </Link>
                                        <Badge
                                            variant={PROFILE[c.profile].variant}
                                        >
                                            {PROFILE[c.profile].label}
                                        </Badge>
                                    </div>
                                    <p className="tabular mt-0.5 font-mono text-[11px] text-muted-foreground">
                                        /{c.slug} ·{' '}
                                        {c.channels
                                            .map((u) => `@${u}`)
                                            .join(', ')}{' '}
                                        · submitted {formatDate(c.submitted_at)}
                                    </p>
                                    <Flags flags={c.flags} />
                                </div>
                                <ConfirmButton
                                    label="Approve"
                                    confirm="Click again"
                                    variant="outline"
                                    disabled={busy !== null}
                                    onConfirm={() =>
                                        postAction(
                                            `/admin/review/${c.id}/approve`,
                                        )
                                    }
                                />
                                <Button asChild size="sm">
                                    <Link href={`/admin/review/${c.id}`}>
                                        Review
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Tray>
            )}

            {channels.length > 0 && (
                <Tray
                    label="Unclaimed channels ready to publish"
                    meta={channels.length}
                >
                    <BulkBar selection={channelSel} count={channels.length}>
                        <ConfirmButton
                            label="Publish selected"
                            confirm={`Click again to publish ${channelSel.picked.length}`}
                            disabled={
                                channelSel.picked.length === 0 || busy !== null
                            }
                            onConfirm={() =>
                                bulk(
                                    'publish_channels',
                                    channelSel.picked,
                                    channelSel.clear,
                                )
                            }
                        />
                    </BulkBar>
                    <ul className="divide-y divide-border/70">
                        {channels.map((c) => (
                            <li key={c.id} className={row}>
                                <Checkbox
                                    checked={channelSel.has(c.id)}
                                    onCheckedChange={() =>
                                        channelSel.toggle(c.id)
                                    }
                                    aria-label={`Select @${c.username}`}
                                />
                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={`/admin/review/channel/${c.id}`}
                                        className="font-medium hover:underline"
                                    >
                                        {c.title}
                                    </Link>
                                    <p className="tabular mt-0.5 font-mono text-[11px] text-muted-foreground">
                                        @{c.username} ·{' '}
                                        {formatCount(c.subscribers)} subscribers
                                    </p>
                                    <Flags flags={c.flags} />
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        postAction(
                                            `/admin/review/channel/${c.id}/publish`,
                                        )
                                    }
                                >
                                    Publish
                                </Button>
                                <Button asChild size="sm">
                                    <Link
                                        href={`/admin/review/channel/${c.id}`}
                                    >
                                        Review
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Tray>
            )}

            {fieldChanges.length > 0 && (
                <Tray label="Name & link changes" meta={fieldChanges.length}>
                    <ul className="divide-y divide-border/70">
                        {fieldChanges.map((c) => (
                            <li key={c.id} className={row}>
                                <div className="min-w-0 flex-1 text-sm">
                                    <Link
                                        href={`/${c.slug}`}
                                        className="font-medium hover:underline"
                                        target="_blank"
                                    >
                                        {c.name}
                                    </Link>
                                    <ul className="mt-1.5 space-y-1 text-xs">
                                        {c.changes.map((ch) => (
                                            <li
                                                key={ch.field}
                                                className="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span className="text-muted-foreground capitalize">
                                                    {ch.field.replace('_', ' ')}
                                                </span>
                                                <span className="rounded-md bg-rose-soft px-1.5 py-0.5 font-mono text-rose line-through decoration-rose/50">
                                                    {ch.old ?? '—'}
                                                </span>
                                                <span className="text-ink-soft">
                                                    →
                                                </span>
                                                <span className="rounded-md bg-mint-soft px-1.5 py-0.5 font-mono text-mint">
                                                    {ch.new ?? '—'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        postAction(
                                            `/admin/review/${c.id}/fields/approve`,
                                        )
                                    }
                                >
                                    Approve
                                </Button>
                                <NoteAction
                                    label="Reject"
                                    title="Reject these changes"
                                    url={`/admin/review/${c.id}/fields/reject`}
                                    field="reason"
                                    required={false}
                                />
                            </li>
                        ))}
                    </ul>
                </Tray>
            )}

            {failed.length > 0 && (
                <Tray label="Failed verification" meta={failed.length}>
                    <BulkBar selection={failedSel} count={failed.length}>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                failedSel.picked.length === 0 || busy !== null
                            }
                            onClick={() =>
                                bulk(
                                    'regenerate',
                                    failedChannels(failedSel.picked),
                                    failedSel.clear,
                                )
                            }
                        >
                            {busy === 'regenerate' && <Spinner />}
                            Rewrite selected
                        </Button>
                        <ConfirmButton
                            label="Approve selected anyway"
                            confirm={`Click again to approve ${failedSel.picked.length}`}
                            disabled={
                                failedSel.picked.length === 0 || busy !== null
                            }
                            onConfirm={() =>
                                bulk(
                                    'approve_summaries',
                                    failedSel.picked,
                                    failedSel.clear,
                                )
                            }
                        />
                    </BulkBar>
                    <ul className="divide-y divide-border/70">
                        {failed.map((f) => (
                            <li key={f.summary_id} className={row}>
                                <Checkbox
                                    checked={failedSel.has(f.summary_id)}
                                    onCheckedChange={() =>
                                        failedSel.toggle(f.summary_id)
                                    }
                                    aria-label={`Select @${f.username}`}
                                />
                                <div className="min-w-0 flex-1 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono text-xs font-medium">
                                            @{f.username}
                                        </span>
                                        <Badge variant="rose">
                                            v{f.version} failed
                                        </Badge>
                                        {f.has_live && (
                                            <Badge variant="mint">
                                                Older profile still live
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="tabular mt-0.5 text-xs text-muted-foreground">
                                        {f.missing.length > 0
                                            ? `No ${f.missing.join(' or ')} that passed`
                                            : 'Nothing required missing'}
                                        {f.dropped > 0 &&
                                            ` · ${f.dropped} part${f.dropped === 1 ? '' : 's'} taken out`}
                                        {' · '}
                                        <span className="font-mono">
                                            {formatDate(f.created_at)}
                                        </span>
                                    </p>
                                </div>
                                <Button asChild size="sm" variant="ghost">
                                    <Link
                                        href={
                                            f.creator_id
                                                ? `/admin/review/${f.creator_id}`
                                                : `/admin/review/channel/${f.channel_id}`
                                        }
                                    >
                                        Inspect
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        postAction(
                                            `/admin/review/channel/${f.channel_id}/regenerate`,
                                        )
                                    }
                                >
                                    Rewrite
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        postAction(
                                            `/admin/summaries/${f.summary_id}/approve`,
                                        )
                                    }
                                >
                                    Approve anyway
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Tray>
            )}
        </PageContainer>
    );
}

ReviewQueue.layout = {
    breadcrumbs: [{ title: 'Review queue', href: '/admin/review' }],
};
