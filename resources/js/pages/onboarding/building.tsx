import { Form, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    ChannelProgressTray,
    ProgressProblem,
} from '@/components/creator/channel-progress';
import type { ChannelProgress } from '@/components/creator/channel-progress';
import OnboardingHeader from '@/components/creator/onboarding-header';
import { Button } from '@/components/ui/button';
import { requestJson } from '@/lib/http';

type Progress = ChannelProgress;

export default function BuildingStep({
    step,
    steps,
    progress: initial,
}: {
    step: number;
    steps: string[];
    progress: Progress;
}) {
    const [progress, setProgress] = useState(initial);

    useEffect(() => {
        if (progress.ready) {
            return;
        }

        const timer = window.setInterval(async () => {
            const { ok, data } = await requestJson<Progress>(
                '/api/onboarding/progress',
            );

            if (ok) {
                setProgress(data);
            }
        }, 5000);

        return () => window.clearInterval(timer);
    }, [progress.ready]);

    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="Building your profile"
                description="This can take a few minutes. We'll message you on Telegram when it's ready."
            />

            <ChannelProgressTray progress={progress} />

            <ProgressProblem progress={progress} />

            {progress.basic && progress.ready && (
                <p className="mt-4 rounded-xl bg-secondary p-3.5 text-sm text-pretty text-muted-foreground">
                    Your channel doesn&apos;t have many text posts yet, so
                    we&apos;ll publish a basic profile with your details,
                    channel info and stats. The AI profile appears once
                    you&apos;ve posted more.
                </p>
            )}

            <div className="mt-8 flex flex-wrap items-center gap-4">
                <Form action="/onboarding/building" method="post">
                    {({ processing }) => (
                        <Button
                            size="lg"
                            type="submit"
                            disabled={!progress.ready || processing}
                        >
                            Review my profile
                            <ArrowRight />
                        </Button>
                    )}
                </Form>
                {!progress.ready && (
                    <Link
                        href="/dashboard"
                        className="text-sm text-muted-foreground underline decoration-border underline-offset-4 hover:text-foreground"
                    >
                        Leave and come back later
                    </Link>
                )}
            </div>
        </>
    );
}
