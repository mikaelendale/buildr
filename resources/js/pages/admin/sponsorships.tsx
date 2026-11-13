import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import { EmptyState, postAction, Tray } from '@/components/admin/ui';
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
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';

type PendingSponsorship = {
    id: number;
    channel_id: number;
    channel_username: string;
    channel_title: string;
    creator_id: number;
    creator_name: string;
    requested_at: string | null;
    review_note: string | null;
};

type ActiveSponsorship = {
    id: number;
    channel_id: number;
    channel_username: string;
    channel_title: string;
    creator_id: number;
    creator_name: string;
    starts_at: string | null;
    ends_at: string | null;
};

type Props = {
    pending: PendingSponsorship[];
    active: ActiveSponsorship[];
};

export default function SponsorsipsPage({ pending, active }: Props) {
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const [endingId, setEndingId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [endReason, setEndReason] = useState('');

    const approve = (id: number) => {
        postAction(`/admin/sponsorships/${id}/approve`, {}, 'Sponsorship approved.');
    };

    const reject = (id: number) => {
        postAction(
            `/admin/sponsorships/${id}/reject`,
            { reason: rejectReason },
            'Sponsorship rejected.',
        );
        setRejectingId(null);
        setRejectReason('');
    };

    const end = (id: number) => {
        postAction(
            `/admin/sponsorships/${id}/end`,
            { reason: endReason },
            'Sponsorship ended.',
        );
        setEndingId(null);
        setEndReason('');
    };

    return (
        <PageContainer
            title="Sponsorships"
            description="Manage creator promotion requests and active sponsorships."
        >
            {pending.length === 0 && active.length === 0 ? (
                <EmptyState glyph="sparkles">
                    No pending requests or active sponsorships.
                </EmptyState>
            ) : (
                <div className="space-y-12">
                    {pending.length > 0 && (
                        <Tray label="Pending requests" meta={pending.length}>
                            <div className="divide-y divide-border/70">
                                {pending.map((s) => (
                                    <div
                                        key={s.id}
                                        className="flex flex-wrap items-start justify-between gap-3 px-3 py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {s.channel_title}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                @{s.channel_username} by{' '}
                                                {s.creator_name}
                                            </p>
                                            {s.requested_at && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Requested{' '}
                                                    {formatDate(s.requested_at)}
                                                </p>
                                            )}
                                            {s.review_note && (
                                                <div className="mt-2 rounded-lg bg-secondary px-2 py-1 text-xs text-muted-foreground">
                                                    {s.review_note}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                className="gap-1 bg-mint text-mint-foreground hover:bg-mint/90"
                                                onClick={() => approve(s.id)}
                                            >
                                                <Check />
                                                Approve
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setRejectingId(s.id);
                                                    setRejectReason('');
                                                }}
                                            >
                                                <X />
                                                Reject
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Tray>
                    )}

                    {active.length > 0 && (
                        <Tray label="Active sponsorships" meta={active.length}>
                            <div className="divide-y divide-border/70">
                                {active.map((s) => (
                                    <div
                                        key={s.id}
                                        className="flex flex-wrap items-start justify-between gap-3 px-3 py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {s.channel_title}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                @{s.channel_username} by{' '}
                                                {s.creator_name}
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                                {s.starts_at && (
                                                    <Badge variant="mint">
                                                        Since{' '}
                                                        {formatDate(
                                                            s.starts_at,
                                                        )}
                                                    </Badge>
                                                )}
                                                {s.ends_at && (
                                                    <Badge variant="amber">
                                                        Until{' '}
                                                        {formatDate(
                                                            s.ends_at,
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-rose hover:text-rose"
                                            onClick={() => {
                                                setEndingId(s.id);
                                                setEndReason('');
                                            }}
                                        >
                                            <X />
                                            End
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </Tray>
                    )}
                </div>
            )}

            <RejectDialog
                open={rejectingId !== null}
                reason={rejectReason}
                onReasonChange={setRejectReason}
                onConfirm={() => {
                    if (rejectingId !== null) reject(rejectingId);
                }}
                onClose={() => setRejectingId(null)}
            />

            <EndDialog
                open={endingId !== null}
                reason={endReason}
                onReasonChange={setEndReason}
                onConfirm={() => {
                    if (endingId !== null) end(endingId);
                }}
                onClose={() => setEndingId(null)}
            />
        </PageContainer>
    );
}

function RejectDialog({
    open,
    reason,
    onReasonChange,
    onConfirm,
    onClose,
}: {
    open: boolean;
    reason: string;
    onReasonChange: (reason: string) => void;
    onConfirm: () => void;
    onClose: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reject sponsorship request</DialogTitle>
                    <DialogDescription>
                        Provide a brief reason. The creator will see this.
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    value={reason}
                    onChange={(e) => onReasonChange(e.target.value)}
                    placeholder="e.g., Channel content does not align with Buildr values..."
                    className="min-h-24"
                />
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={onConfirm}
                        disabled={reason.trim().length < 5}
                    >
                        Reject
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function EndDialog({
    open,
    reason,
    onReasonChange,
    onConfirm,
    onClose,
}: {
    open: boolean;
    reason: string;
    onReasonChange: (reason: string) => void;
    onConfirm: () => void;
    onClose: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>End sponsorship</DialogTitle>
                    <DialogDescription>
                        Optionally provide a reason for the creator.
                    </DialogDescription>
                </DialogHeader>
                <Textarea
                    value={reason}
                    onChange={(e) => onReasonChange(e.target.value)}
                    placeholder="e.g., Campaign period ended..."
                    className="min-h-24"
                />
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={onConfirm}>End sponsorship</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

SponsorsipsPage.layout = {
    breadcrumbs: [
        { title: 'Featured', href: '/admin/featured' },
        { title: 'Sponsorships' },
    ],
};
