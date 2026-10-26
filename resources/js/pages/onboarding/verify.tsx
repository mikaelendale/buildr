import { Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import ClaimCodeCard from '@/components/creator/claim-code-card';
import type { ClaimPayload } from '@/components/creator/claim-code-card';
import OnboardingHeader from '@/components/creator/onboarding-header';
import Glyph from '@/components/figures/glyph';
import { Button } from '@/components/ui/button';

export default function VerifyStep({
    step,
    steps,
    claim,
}: {
    step: number;
    steps: string[];
    claim: ClaimPayload | null;
}) {
    const [verified, setVerified] = useState(claim?.status === 'verified');

    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="Prove it's your channel"
                description="Nothing is published without a verified channel."
            />

            {claim ? (
                <ClaimCodeCard
                    claim={claim}
                    onVerified={() => setVerified(true)}
                />
            ) : (
                <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-5">
                    <Glyph name="empty" className="size-10" />
                    <p className="text-sm text-muted-foreground">
                        No channel yet.{' '}
                        <Link
                            href="/onboarding/channel"
                            className="text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                        >
                            Add your channel
                        </Link>{' '}
                        first.
                    </p>
                </div>
            )}

            <div className="mt-8 flex flex-wrap items-center gap-4">
                {verified ? (
                    <Button
                        size="lg"
                        onClick={() => router.visit('/onboarding/basics')}
                    >
                        Continue
                        <ArrowRight />
                    </Button>
                ) : (
                    <Link
                        href="/dashboard"
                        className="text-sm text-muted-foreground underline decoration-border underline-offset-4 hover:text-foreground"
                    >
                        I&apos;ll do it later
                    </Link>
                )}
            </div>
        </>
    );
}
