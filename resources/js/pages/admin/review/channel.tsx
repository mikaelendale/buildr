import { Flag } from 'lucide-react';
import {
    Card,
    NoteAction,
    postAction,
    StatusBadge,
} from '@/components/admin/ui';
import VerificationReport from '@/components/admin/verification-report';
import SectionEditor from '@/components/creator/section-editor';
import type { ChannelEditor } from '@/components/creator/section-editor';
import PageContainer from '@/components/page-container';
import ChannelTabs from '@/components/profile/channel-tabs';
import ProfileHeader from '@/components/profile/profile-header';
import { Button } from '@/components/ui/button';
import type { ChannelProfile } from '@/types';

type Props = {
    channel: ChannelProfile & {
        visibility: string;
        review_status: string | null;
    };
    editor: ChannelEditor;
    verification: never;
    flags: { key: string; label: string }[];
};

export default function ReviewChannel({
    channel,
    editor,
    verification,
    flags,
}: Props) {
    return (
        <PageContainer
            title={`Review: @${channel.username}`}
            wide
            actions={
                <>
                    <Button
                        size="sm"
                        onClick={() =>
                            postAction(
                                `/admin/review/channel/${channel.id}/publish`,
                            )
                        }
                    >
                        Publish
                    </Button>
                    <Button
                        size="sm"
                        variant="brand"
                        onClick={() =>
                            postAction(
                                `/admin/review/channel/${channel.id}/publish`,
                                { feature: true },
                            )
                        }
                    >
                        Publish + feature
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            postAction(
                                `/admin/review/channel/${channel.id}/regenerate`,
                            )
                        }
                    >
                        Regenerate
                    </Button>
                    <NoteAction
                        label="Don't publish"
                        title="Keep this channel hidden"
                        url={`/admin/review/channel/${channel.id}/reject`}
                        field="reason"
                        required={false}
                        variant="destructive"
                    />
                </>
            }
        >
            <div className="flex flex-wrap gap-1.5">
                <StatusBadge value={channel.visibility} />
                <StatusBadge value={channel.review_status} />
            </div>

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
                            name={channel.name}
                            avatar={channel.avatar}
                            subtitle={
                                <span className="font-mono text-xs">
                                    @{channel.username}
                                </span>
                            }
                            hook={channel.hook}
                            vibeTags={channel.vibe_tags}
                        >
                            {channel.description && (
                                <p className="text-sm text-muted-foreground">
                                    {channel.description}
                                </p>
                            )}
                        </ProfileHeader>
                        <ChannelTabs
                            sections={channel.sections}
                            showHowTheyWork={false}
                        />
                    </div>
                </div>
                <div className="space-y-4">
                    <Card title="Verification">
                        <VerificationReport report={verification} />
                    </Card>
                    <Card title="Edit / hide sections">
                        <SectionEditor
                            editor={editor}
                            baseUrl="/admin/sections"
                        />
                    </Card>
                </div>
            </div>
        </PageContainer>
    );
}
