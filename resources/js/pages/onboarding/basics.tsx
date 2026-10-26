import BasicsForm from '@/components/creator/basics-form';
import type { Basics } from '@/components/creator/basics-form';
import OnboardingHeader from '@/components/creator/onboarding-header';

type Props = {
    step: number;
    steps: string[];
    basics: Basics;
    suggestedTags: string[];
};

export default function BasicsStep({
    step,
    steps,
    basics,
    suggestedTags,
}: Props) {
    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="The basics"
                description={
                    <>
                        While you fill this in, we&apos;re already reading your
                        channel.
                        <span className="ml-2 inline-flex translate-y-[-1px] items-center gap-1.5 rounded-full bg-card px-2 py-0.5 align-middle text-xs text-muted-foreground ring-1 ring-border/80">
                            <span className="size-1.5 animate-pulse rounded-full bg-brand motion-reduce:animate-none" />
                            reading
                        </span>
                    </>
                }
            />
            <BasicsForm
                initial={basics}
                action="/onboarding/basics"
                submitLabel="Save and continue"
                suggestedTags={suggestedTags}
            />
        </>
    );
}
