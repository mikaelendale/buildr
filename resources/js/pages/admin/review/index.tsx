import { Link } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import {
    EmptyState,
    NoteAction,
    postAction,
    Tray,
} from '@/components/admin/ui';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCount, formatDate } from '@/lib/format';

type FlagItem = { key: string; label: string };

type Props = {
    creators: {
        id: number;
        name: string;
        slug: string;
        submitted_at: string | null;
        channels: string[];
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
    }[];
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

const row = 'flex flex-wrap items-center gap-3 px-4 py-3.5';

export default function ReviewQueue({
    creators,
    channels,
    fieldChanges,
    failed,
}: Props) {
    const empty =
        creators.length +
            channels.length +
            fieldChanges.length +
            failed.length ===
        0;

    return (
        <PageContainer
            title="Review queue"
            description="Oldest first. Every action messages the creator and lands in the audit log."
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
                    <ul className="divide-y divide-border/70">
                        {creators.map((c) => (
                            <li key={c.id} className={row}>
                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={`/admin/review/${c.id}`}
                                        className="font-medium hover:underline"
                                    >
                                        {c.name}
                                    </Link>
                                    <p className="tabular mt-0.5 font-mono text-[11px] text-muted-foreground">
                                        /{c.slug} ·{' '}
                                        {c.channels
                                            .map((u) => `@${u}`)
                                            .join(', ')}{' '}
                                        · submitted {formatDate(c.submitted_at)}
                                    </p>
                                    <Flags flags={c.flags} />
                                </div>
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
                    <ul className="divide-y divide-border/70">
                        {channels.map((c) => (
                            <li key={c.id} className={row}>
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
                    <ul className="divide-y divide-border/70">
                        {failed.map((f) => (
                            <li key={f.summary_id} className={row}>
                                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-sm">
                                    <span className="font-mono text-xs font-medium">
                                        @{f.username}
                                    </span>
                                    <Badge variant="rose">
                                        v{f.version} failed
                                    </Badge>
                                    <span className="tabular font-mono text-[11px] text-muted-foreground">
                                        {formatDate(f.created_at)}
                                    </span>
                                </div>
                                <Button asChild size="sm" variant="outline">
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
                                    Regenerate
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
