import { router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import ClaimCodeCard from '@/components/creator/claim-code-card';
import type { ClaimPayload } from '@/components/creator/claim-code-card';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';

const TITLES: Record<ClaimPayload['purpose'], string> = {
    claim: 'Verify your channel',
    removal: 'Remove your channel',
    dispute: 'Prove you own this channel',
    rename: 'Confirm the new handle',
};

export default function ClaimPage({ claim }: { claim: ClaimPayload }) {
    const [done, setDone] = useState(claim.status === 'verified');

    return (
        <PageContainer
            title={TITLES[claim.purpose]}
            description={`@${claim.username}`}
        >
            <div className="max-w-xl">
                <ClaimCodeCard claim={claim} onVerified={() => setDone(true)} />
                {done && (
                    <Button
                        size="lg"
                        className="mt-6"
                        onClick={() =>
                            router.visit(
                                claim.purpose === 'removal'
                                    ? '/'
                                    : '/dashboard/channels',
                            )
                        }
                    >
                        Continue
                        <ArrowRight />
                    </Button>
                )}
            </div>
        </PageContainer>
    );
}
