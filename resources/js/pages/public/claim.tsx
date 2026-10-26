import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import Display from '@/components/brand/display';
import DitherField from '@/components/figures/dither-field';
import Glyph from '@/components/figures/glyph';
import type { GlyphName } from '@/components/figures/glyph';

const POINTS: { glyph: GlyphName; title: string; body: string }[] = [
    {
        glyph: 'profile',
        title: 'What you get',
        body: 'A verified page with your name, socials, projects and journey, written from your own posts.',
    },
    {
        glyph: 'feed',
        title: 'What we read',
        body: "Only your channel's public web preview. Never your chats, groups or Telegram account.",
    },
    {
        glyph: 'claim',
        title: 'You stay in control',
        body: 'Edit or hide any section, add projects, or remove the channel entirely.',
    },
];

const STEPS = [
    'Log in with Telegram',
    'Paste a short code in your channel bio',
    'Review the page we wrote, then publish',
];

export default function Claim({ channel }: { channel: string | null }) {
    const { auth } = usePage().props;
    const start = auth.user ? '/onboarding' : '/login?intended=/onboarding';

    return (
        <>
            <Head title="Claim your page">
                <meta
                    head-key="description"
                    name="description"
                    content="Run a Telegram channel? Claim your Buildr page in two minutes."
                />
            </Head>
            <DitherField
                className="absolute inset-x-0 top-0 h-[300px] sm:h-[400px]"
                tone="mint"
                shape="swirl"
                secondaryShape="simplex"
                fade="band"
                speed={0.18}
                size={2.5}
            />

            <div className="relative mx-auto max-w-[1080px] px-5 pt-40 sm:pt-56">
                <div className="max-w-2xl">
                    <Display
                        as="h1"
                        size="xl"
                        lead={
                            channel
                                ? `Is @${channel} yours?`
                                : 'Claim your page'
                        }
                        stack
                    >
                        {channel ? 'Claim it.' : 'in two minutes.'}
                    </Display>
                    <p className="mt-5 max-w-md text-base leading-relaxed text-muted-foreground">
                        Log in with Telegram, paste a short code in your channel
                        description, and we&apos;ll do the rest.
                    </p>

                    <ol className="mt-8 space-y-2.5">
                        {STEPS.map((step, i) => (
                            <li
                                key={step}
                                className="flex items-center gap-3 text-sm"
                            >
                                <span className="flex size-6 items-center justify-center rounded-full bg-card font-mono text-[11px] text-muted-foreground shadow-[0_1px_2px_oklch(0_0_0/0.06)] ring-1 ring-border/80">
                                    {i + 1}
                                </span>
                                {step}
                            </li>
                        ))}
                    </ol>

                    <Link
                        href={start}
                        className="group mt-9 inline-flex items-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <span className="rounded-full bg-brand px-5 py-2.5 text-[15px] font-medium text-brand-foreground transition-[filter] group-hover:brightness-[0.97]">
                            {auth.user
                                ? 'Continue'
                                : 'Log in with Telegram to start'}
                        </span>
                        <span className="-ml-0.5 flex size-11 items-center justify-center rounded-full bg-brand text-brand-foreground transition-transform duration-200 ease-out group-hover:translate-x-0.5">
                            <ArrowRight className="size-4" />
                        </span>
                    </Link>
                </div>

                <div className="mt-16 grid gap-3 sm:grid-cols-3">
                    {POINTS.map((point) => (
                        <div
                            key={point.title}
                            className="group surface-fade p-5 pb-8"
                        >
                            <Glyph name={point.glyph} className="-ml-1" />
                            <h2 className="mt-5 font-medium">{point.title}</h2>
                            <p className="mt-1 text-sm leading-relaxed text-pretty text-muted-foreground">
                                {point.body}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
