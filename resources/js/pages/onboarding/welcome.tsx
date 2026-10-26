import { Form } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import OnboardingHeader from '@/components/creator/onboarding-header';
import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';
import { Button } from '@/components/ui/button';

const POINTS: { glyph: GlyphName; title: string; body: string }[] = [
    {
        glyph: 'profile',
        title: 'What Buildr is',
        body: 'A directory where people find Telegram creators, see what they build, their journey and all their socials.',
    },
    {
        glyph: 'feed',
        title: 'What we read',
        body: "Only your channel's public posts, the same web preview anyone can open. Never your chats, groups or account.",
    },
    {
        glyph: 'claim',
        title: 'What you control',
        body: 'Everything. Edit or hide any section, add projects, and remove your channel any time.',
    },
];

export default function Welcome({
    step,
    steps,
}: {
    step: number;
    steps: string[];
}) {
    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="Let's build your page"
                description="It takes about two minutes, then we write your profile in the background."
            />

            <ul className="surface divide-y divide-border/70">
                {POINTS.map((point) => (
                    <li
                        key={point.title}
                        className="group flex items-start gap-4 p-5"
                    >
                        <Glyph name={point.glyph} className="-my-1 size-11" />
                        <div>
                            <h2 className="font-medium tracking-tight">
                                {point.title}
                            </h2>
                            <p className="mt-0.5 text-sm leading-relaxed text-pretty text-muted-foreground">
                                {point.body}
                            </p>
                        </div>
                    </li>
                ))}
            </ul>

            <Form action="/onboarding/welcome" method="post" className="mt-8">
                {({ processing }) => (
                    <Button size="lg" type="submit" disabled={processing}>
                        Get started
                        <ArrowRight />
                    </Button>
                )}
            </Form>
        </>
    );
}
