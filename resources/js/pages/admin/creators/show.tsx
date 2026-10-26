import { Link, useForm } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import {
    Card,
    EmptyState,
    NoteAction,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';

type Props = {
    creator: {
        id: number;
        slug: string;
        display_name: string;
        real_name: string | null;
        real_name_am: string | null;
        bio: string | null;
        location: string | null;
        pending_changes: Record<string, string> | null;
        review_note: string | null;
        status: string;
        published_at: string | null;
        featured: boolean;
        channels: { id: number; username: string; claim_status: string }[];
        socials: { platform: string; url: string }[];
    };
    user: {
        id: number;
        name: string;
        telegram_username: string | null;
        banned_at: string | null;
        ban_reason: string | null;
        created_at: string | null;
        last_login_at: string | null;
        logins: { method: string; at: string | null; agent: string | null }[];
    } | null;
    audit: {
        action: string;
        actor: string | null;
        at: string | null;
        meta: Record<string, unknown> | null;
    }[];
};

export default function AdminCreator({ creator, user, audit }: Props) {
    const form = useForm({
        display_name: creator.display_name,
        real_name: creator.real_name ?? '',
        real_name_am: creator.real_name_am ?? '',
        slug: creator.slug,
        bio: creator.bio ?? '',
        location: creator.location ?? '',
    });

    return (
        <PageContainer
            title={creator.display_name}
            description={`/${creator.slug}`}
            wide
            actions={
                <>
                    <Button asChild size="sm" variant="outline">
                        <a
                            href={`/${creator.slug}`}
                            target="_blank"
                            rel="noopener"
                        >
                            View <ArrowUpRight />
                        </a>
                    </Button>
                    {creator.status === 'pending_review' && (
                        <Button asChild size="sm">
                            <Link href={`/admin/review/${creator.id}`}>
                                Review
                            </Link>
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            postAction(
                                `/admin/creators/${creator.id}/feature`,
                                { featured: !creator.featured },
                            )
                        }
                    >
                        {creator.featured ? 'Unfeature' : 'Feature'}
                    </Button>
                    {creator.status === 'published' && (
                        <NoteAction
                            label="Unpublish"
                            title="Hide this profile"
                            url={`/admin/creators/${creator.id}/unpublish`}
                            field="reason"
                            required={false}
                        />
                    )}
                    {user &&
                        (user.banned_at ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    postAction(
                                        `/admin/creators/${creator.id}/ban`,
                                        { unban: true },
                                    )
                                }
                            >
                                Unban
                            </Button>
                        ) : (
                            <NoteAction
                                label="Ban user"
                                title="Ban this user"
                                url={`/admin/creators/${creator.id}/ban`}
                                field="reason"
                                variant="destructive"
                                description="Blocks login and hides all their profiles."
                            />
                        ))}
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-1.5 text-sm">
                <StatusBadge value={creator.status} />
                {creator.featured && <Badge variant="violet">featured</Badge>}
                {creator.published_at && (
                    <span className="tabular ml-1 font-mono text-xs text-muted-foreground">
                        published {formatDate(creator.published_at)}
                    </span>
                )}
            </div>

            {user?.banned_at && (
                <div className="rounded-2xl bg-rose-soft p-4 text-sm text-rose ring-1 ring-rose/15">
                    <span className="font-medium">Banned</span>
                    {user.ban_reason ? ` · ${user.ban_reason}` : ''}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[1.1fr_1fr]">
                <Card title="Edit profile">
                    <p className="-mt-2 mb-4 text-xs text-muted-foreground">
                        Every change is written to the audit log.
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(`/admin/creators/${creator.id}`, {
                                preserveScroll: true,
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(
                                [
                                    'display_name',
                                    'real_name',
                                    'real_name_am',
                                    'slug',
                                    'location',
                                ] as const
                            ).map((field) => (
                                <div key={field} className="space-y-1.5">
                                    <Label
                                        htmlFor={field}
                                        className="text-xs font-normal text-muted-foreground capitalize"
                                    >
                                        {field.replace(/_/g, ' ')}
                                    </Label>
                                    <Input
                                        id={field}
                                        value={form.data[field]}
                                        onChange={(e) =>
                                            form.setData(field, e.target.value)
                                        }
                                        className={
                                            field === 'slug'
                                                ? 'font-mono'
                                                : undefined
                                        }
                                    />
                                    <InputError message={form.errors[field]} />
                                </div>
                            ))}
                        </div>
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="bio"
                                className="text-xs font-normal text-muted-foreground"
                            >
                                Bio
                            </Label>
                            <Textarea
                                id="bio"
                                rows={3}
                                value={form.data.bio}
                                onChange={(e) =>
                                    form.setData('bio', e.target.value)
                                }
                            />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </form>
                </Card>

                <div className="space-y-4">
                    <Card title="Channels">
                        {creator.channels.length === 0 ? (
                            <EmptyState glyph="feed" className="py-6">
                                No channels attached.
                            </EmptyState>
                        ) : (
                            <ul className="divide-y divide-border/70">
                                {creator.channels.map((c) => (
                                    <li
                                        key={c.id}
                                        className="flex items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0"
                                    >
                                        <Link
                                            href={`/admin/channels/${c.id}`}
                                            className="font-mono text-xs hover:underline"
                                        >
                                            @{c.username}
                                        </Link>
                                        <StatusBadge value={c.claim_status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Merge into another profile">
                        <MergeForm creatorId={creator.id} />
                    </Card>

                    {user && (
                        <Card title="Login history">
                            <p className="tabular mb-3 font-mono text-[11px] text-muted-foreground">
                                @{user.telegram_username ?? '—'} · joined{' '}
                                {formatDate(user.created_at)} · last login{' '}
                                {formatDate(user.last_login_at)}
                            </p>
                            <ul className="divide-y divide-border/70 text-xs">
                                {user.logins.map((l, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-2 py-2"
                                    >
                                        <span className="tabular font-mono whitespace-nowrap">
                                            {formatDate(l.at)}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="text-muted-foreground"
                                        >
                                            {l.method}
                                        </Badge>
                                        <span className="truncate text-muted-foreground">
                                            {l.agent}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            <Card title="Audit trail">
                {audit.length === 0 ? (
                    <EmptyState glyph="journey" className="py-6">
                        Nothing logged yet.
                    </EmptyState>
                ) : (
                    <ol className="space-y-2.5">
                        {audit.map((a, i) => (
                            <li
                                key={i}
                                className="flex flex-wrap items-center gap-2 text-xs"
                            >
                                <span className="size-1.5 rounded-full bg-border" />
                                <span className="tabular font-mono text-muted-foreground">
                                    {formatDate(a.at)}
                                </span>
                                <span className="rounded-md bg-secondary px-1.5 py-0.5 font-mono">
                                    {a.action}
                                </span>
                                <span className="text-muted-foreground">
                                    {a.actor ?? 'system'}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </Card>
        </PageContainer>
    );
}

function MergeForm({ creatorId }: { creatorId: number }) {
    const form = useForm({ into: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/admin/creators/${creatorId}/merge`);
            }}
            className="space-y-3"
        >
            <p className="text-xs text-pretty text-muted-foreground">
                Moves channels, projects and socials into the target profile,
                then deletes this one.
            </p>
            <div className="flex gap-2">
                <Input
                    value={form.data.into}
                    onChange={(e) => form.setData('into', e.target.value)}
                    placeholder="target slug"
                    className="font-mono"
                />
                <Button
                    type="submit"
                    variant="outline"
                    className="h-10"
                    disabled={form.processing || !form.data.into}
                >
                    Merge
                </Button>
            </div>
        </form>
    );
}
