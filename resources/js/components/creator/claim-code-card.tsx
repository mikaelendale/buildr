import { router } from '@inertiajs/react';
import { Check, Copy, Loader2, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import Glyph from '@/components/figures/glyph';
import { Button } from '@/components/ui/button';
import { requestJson } from '@/lib/http';

export type ClaimPayload = {
    id: number;
    code: string;
    username: string;
    purpose: 'claim' | 'removal' | 'dispute' | 'rename';
    status: 'pending' | 'verified' | 'expired' | 'failed' | 'disputed';
    attempts: number;
    expires_at: string;
    last_checked_at: string | null;
};

const INTRO: Record<ClaimPayload['purpose'], string> = {
    claim: 'Paste this code anywhere in your channel description (or post it in the channel). You can delete it after we verify.',
    removal:
        'To prove the channel is yours, paste this code in the channel description (or post it). As soon as we see it, the channel is removed.',
    dispute:
        'Whoever can put this code in the channel description first keeps the channel on their profile.',
    rename: 'Paste this code in the description of the channel under its new name, so we can confirm it is the same channel.',
};

/**
 * Code + copy button + instructions + "Check now" (plan §6.2). Also polls
 * quietly every 30s so the creator can just wait.
 */
export default function ClaimCodeCard({
    claim: initial,
    onVerified,
}: {
    claim: ClaimPayload;
    onVerified?: (claim: ClaimPayload) => void;
}) {
    const [claim, setClaim] = useState(initial);
    const [checking, setChecking] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const verified = claim.status === 'verified';

    const check = useCallback(
        async (manual: boolean) => {
            setChecking(manual);

            const { ok, status, data } = await requestJson<{
                claim: ClaimPayload;
                verified: boolean;
                message: string;
            }>(`/api/claims/${claim.id}/check`, 'POST');

            setChecking(false);

            if (status === 429) {
                if (manual)
                    setMessage('Hang on a few seconds before checking again.');

                return;
            }

            if (!ok) {
                if (manual)
                    setMessage('Something went wrong. Try again in a moment.');

                return;
            }

            setClaim(data.claim);
            setMessage(data.message);

            if (data.verified) {
                onVerified?.(data.claim);
            }
        },
        [claim.id, onVerified],
    );

    useEffect(() => {
        if (claim.status !== 'pending') {
            return;
        }

        const timer = window.setInterval(() => void check(false), 30_000);

        return () => window.clearInterval(timer);
    }, [claim.status, check]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(claim.code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy this code', claim.code);
        }
    };

    if (verified) {
        return (
            <div className="group flex flex-col items-center rounded-2xl bg-mint-soft px-6 py-8 text-center ring-1 ring-mint/15">
                <div className="flex size-16 items-center justify-center rounded-2xl bg-card shadow-soft">
                    <Glyph name="claim" className="size-11" />
                </div>
                <h2 className="mt-4 text-xl font-light tracking-tight">
                    <span className="font-mono text-base">
                        @{claim.username}
                    </span>{' '}
                    is verified
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    You can remove the code from your channel now.
                </p>
            </div>
        );
    }

    return (
        <div className="surface space-y-6 p-5 sm:p-6">
            <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                {INTRO[claim.purpose]}
            </p>

            <div className="rounded-[18px] bg-secondary p-1 ring-1 ring-border/60">
                <div className="flex items-center gap-3 rounded-[14px] bg-card py-3 pr-3 pl-5 shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                    <code className="flex-1 font-mono text-xl font-medium tracking-[0.18em] select-all sm:text-2xl">
                        {claim.code}
                    </code>
                    <Button
                        variant={copied ? 'secondary' : 'outline'}
                        size="sm"
                        onClick={copy}
                        aria-live="polite"
                    >
                        {copied ? <Check /> : <Copy />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
                <div className="flex items-center justify-between gap-2 px-3 pt-1.5 pb-0.5 text-xs text-muted-foreground">
                    <span>
                        For{' '}
                        <span className="font-mono text-foreground/80">
                            @{claim.username}
                        </span>
                    </span>
                    <span className="tabular font-mono">
                        expires{' '}
                        {new Date(claim.expires_at).toLocaleString([], {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                        })}
                    </span>
                </div>
            </div>

            <ol className="space-y-3 text-sm">
                {[
                    <>
                        Open{' '}
                        <span className="font-mono">@{claim.username}</span> in
                        Telegram → Edit → Description.
                    </>,
                    <>Paste the code anywhere and save.</>,
                    <>
                        Tap <span className="font-medium">Check now</span>. We
                        also keep checking every few minutes.
                    </>,
                ].map((line, i) => (
                    <li key={i} className="flex items-start gap-3">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-card font-mono text-[11px] text-muted-foreground shadow-[0_1px_2px_oklch(0_0_0/0.06)] ring-1 ring-border/80">
                            {i + 1}
                        </span>
                        <span className="pt-0.5 text-pretty text-foreground/85">
                            {line}
                        </span>
                    </li>
                ))}
            </ol>

            {claim.status === 'pending' ? (
                <div className="space-y-2.5">
                    <Button
                        className="w-full"
                        size="lg"
                        onClick={() => void check(true)}
                        disabled={checking}
                    >
                        {checking ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <RefreshCw />
                        )}
                        {checking ? 'Checking…' : 'Check now'}
                    </Button>
                    {message && (
                        <p
                            className="text-center text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {message}
                        </p>
                    )}
                </div>
            ) : (
                <div className="space-y-3 rounded-xl bg-rose-soft p-4 text-center text-sm text-rose ring-1 ring-rose/15">
                    <p>{message ?? `This code is ${claim.status}.`}</p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.visit('/dashboard/channels')}
                    >
                        Start again
                    </Button>
                </div>
            )}
        </div>
    );
}
