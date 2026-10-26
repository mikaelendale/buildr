import { Link } from '@inertiajs/react';
import { ArrowUpRight, Flag } from 'lucide-react';
import { useState } from 'react';
import {
    Card,
    NoteAction,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import VerificationReport from '@/components/admin/verification-report';
import SectionEditor from '@/components/creator/section-editor';
import type { ChannelEditor } from '@/components/creator/section-editor';
import Linkify from '@/components/linkify';
import PageContainer from '@/components/page-container';
import ChannelChips from '@/components/profile/channel-chips';
import ChannelTabs from '@/components/profile/channel-tabs';
import ProfileHeader from '@/components/profile/profile-header';
import { Button } from '@/components/ui/button';
import Segmented from '@/components/ui/segmented';
import { formatDate } from '@/lib/format';
import type { CreatorProfile } from '@/types';

type Props = {
    creator: {
        id: number;
        name: string;
        slug: string;
        status: string;
        review_note: string | null;
        submitted_at: string | null;
        pending_changes: Record<string, string | null> | null;
        user: {
            id: number;
            name: string;
            telegram_username: string | null;
            created_at: string | null;
            banned: boolean;
        };
    };
    profile: CreatorProfile;
    editor: ChannelEditor[];
    verification: {
        username: string;
        report: never;
        claim: {
            code: string;
            verified_at: string;
            found_in: string;
            found_msg_id: number | null;
        } | null;
    }[];
    flags: { key: string; label: string }[];
    templates: { changes: string[]; reject: string[] };
};

/**
 * Left: the exact public preview. Right: verification, claim proof, flags and edits (plan §10.2).
 */
export default function ReviewCreator({
    creator,
    profile,
    editor,
    verification,
    flags,
    templates,
}: Props) {
    const [editIndex, setEditIndex] = useState(0);

    return (
        <PageContainer
            title={`Review: ${creator.name}`}
            description={`/${creator.slug} · submitted ${formatDate(creator.submitted_at)}`}
            wide
            actions={
                <>
                    <Button
                        size="sm"
                        onClick={() =>
                            postAction(`/admin/review/${creator.id}/approve`)
                        }
                    >
                        Approve
                    </Button>
                    <Button
                        size="sm"
                        variant="brand"
                        onClick={() =>
                            postAction(`/admin/review/${creator.id}/approve`, {
                                feature: true,
                            })
                        }
                    >
                        Approve + feature
                    </Button>
                    <NoteAction
                        label="Request changes"
                        title="Ask for changes"
                        url={`/admin/review/${creator.id}/changes`}
                        templates={templates.changes}
                    />
                    <NoteAction
                        label="Reject"
                        title="Reject this profile"
                        url={`/admin/review/${creator.id}/reject`}
                        field="reason"
                        templates={templates.reject}
                        variant="destructive"
                    />
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                <StatusBadge value={creator.status} />
                {creator.user.banned && (
                    <span className="text-xs text-rose">banned user</span>
                )}
                <span className="tabular font-mono text-xs text-muted-foreground">
                    @{creator.user.telegram_username ?? '—'} · since{' '}
                    {formatDate(creator.user.created_at)}
                </span>
                <Link
                    href={`/admin/creators/${creator.id}`}
                    className="text-sm text-sky hover:underline"
                >
                    Creator admin
                </Link>
                <a
                    href={`/${creator.slug}`}
                    target="_blank"
                    rel="noopener"
                    className="inline-flex items-center gap-0.5 text-sm text-sky hover:underline"
                >
                    Open page
                    <ArrowUpRight className="size-3.5" />
                </a>
            </div>

            {creator.review_note && (
                <p className="rounded-2xl bg-secondary/70 px-4 py-3 text-sm text-foreground/80">
                    <span className="text-muted-foreground">
                        Last review note:{' '}
                    </span>
                    {creator.review_note}
                </p>
            )}

            {flags.length > 0 && (
                <div className="space-y-1.5 rounded-2xl bg-amber-soft p-4 text-sm text-amber ring-1 ring-amber/15">
                    {flags.map((f) => (
                        <p
                            key={f.key + f.label}
                            className="flex items-center gap-2"
                        >
                            <Flag className="size-4 shrink-0" />
                            {f.label}
                        </p>
                    ))}
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div className="rounded-[24px] bg-secondary p-1 ring-1 ring-border/60">
                    <div className="px-4 pt-2 pb-2.5 text-xs text-muted-foreground">
                        Public page preview
                    </div>
                    <div className="space-y-6 rounded-[20px] bg-background p-5 ring-1 ring-border/50 sm:p-7">
                        <ProfileHeader
                            name={profile.name}
                            avatar={profile.avatar}
                            verified
                            subtitle={
                                [profile.real_name, profile.real_name_am]
                                    .filter(Boolean)
                                    .join(' · ') || undefined
                            }
                            hook={profile.hook}
                            vibeTags={profile.vibe_tags}
                            location={profile.location}
                        />
                        <ChannelChips channels={profile.channels} />
                        <ChannelTabs sections={profile.sections} />
                        {profile.bio && (
                            <p className="text-sm leading-relaxed whitespace-pre-line text-foreground/80">
                                <Linkify text={profile.bio} />
                            </p>
                        )}
                        {profile.socials.length > 0 && (
                            <ul className="divide-y divide-border/70 rounded-2xl bg-card text-sm ring-1 ring-border/70">
                                {profile.socials.map((s) => (
                                    <li
                                        key={s.url}
                                        className="flex items-center gap-3 px-4 py-2.5"
                                    >
                                        <span className="w-24 shrink-0 text-muted-foreground">
                                            {s.label}
                                        </span>
                                        <a
                                            href={s.url}
                                            target="_blank"
                                            rel="noopener"
                                            className="truncate font-mono text-xs text-sky hover:underline"
                                        >
                                            {s.url}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="space-y-4">
                    {verification.map((v) => (
                        <Card key={v.username} title={`@${v.username}`}>
                            {v.claim ? (
                                <p className="mb-4 rounded-xl bg-mint-soft px-3 py-2 text-xs text-mint ring-1 ring-mint/15">
                                    Claim proof{' '}
                                    <code className="rounded bg-card px-1 py-0.5 font-mono text-foreground">
                                        {v.claim.code}
                                    </code>{' '}
                                    found in {v.claim.found_in}
                                    {v.claim.found_msg_id
                                        ? ` (post #${v.claim.found_msg_id})`
                                        : ''}{' '}
                                    on {formatDate(v.claim.verified_at)}
                                </p>
                            ) : (
                                <p className="mb-4 rounded-xl bg-amber-soft px-3 py-2 text-xs text-amber ring-1 ring-amber/15">
                                    No verified claim on record.
                                </p>
                            )}
                            <VerificationReport report={v.report} />
                        </Card>
                    ))}

                    {editor.length > 0 && (
                        <Card title="Edit / hide sections">
                            {editor.length > 1 && (
                                <Segmented
                                    label="Channel to edit"
                                    className="mb-4"
                                    value={String(editIndex)}
                                    onChange={(v) => setEditIndex(Number(v))}
                                    items={editor.map((e, i) => ({
                                        value: String(i),
                                        label: `@${e.channel.username}`,
                                    }))}
                                />
                            )}
                            <SectionEditor
                                editor={editor[editIndex]}
                                baseUrl="/admin/sections"
                            />
                            <Button
                                className="mt-4"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    postAction(
                                        `/admin/review/channel/${editor[editIndex].channel_id}/regenerate`,
                                    )
                                }
                            >
                                Regenerate whole summary
                            </Button>
                        </Card>
                    )}
                </div>
            </div>
        </PageContainer>
    );
}
