import { Form, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, Eye, PenLine, Send } from 'lucide-react';
import { useState } from 'react';
import OnboardingHeader from '@/components/creator/onboarding-header';
import SectionEditor from '@/components/creator/section-editor';
import type { ChannelEditor } from '@/components/creator/section-editor';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import ChannelSection from '@/components/profile/channel-section';
import { Button } from '@/components/ui/button';
import Segmented from '@/components/ui/segmented';

type Props = {
    step: number;
    steps: string[];
    editor: ChannelEditor[];
    regenerationsLeft: number;
    creator: {
        name: string;
        slug: string;
        status: string;
        review_note: string | null;
    };
};

export default function ReviewStep({
    step,
    steps,
    editor,
    regenerationsLeft,
    creator,
}: Props) {
    const [tab, setTab] = useState<'preview' | 'edit'>('preview');
    const [index, setIndex] = useState(0);
    const current = editor[index];

    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="Review your profile"
                description="Edit, hide or regenerate any section. Projects can be changed from your dashboard any time."
            />

            {creator.status === 'changes_requested' && creator.review_note && (
                <div className="mb-6 flex gap-3 rounded-2xl bg-amber-soft p-4 text-sm ring-1 ring-amber/15">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber" />
                    <div>
                        <p className="font-medium text-amber">
                            Our reviewer asked for changes
                        </p>
                        <p className="mt-1 text-pretty text-foreground/80">
                            {creator.review_note}
                        </p>
                    </div>
                </div>
            )}

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <Segmented
                    label="Review mode"
                    value={tab}
                    onChange={(v) => setTab(v as 'preview' | 'edit')}
                    items={[
                        { value: 'preview', label: 'Preview', icon: Eye },
                        {
                            value: 'edit',
                            label: 'Edit sections',
                            icon: PenLine,
                        },
                    ]}
                />
                {editor.length > 1 && (
                    <Segmented
                        label="Channels"
                        value={String(index)}
                        onChange={(v) => setIndex(Number(v))}
                        items={editor.map((e, i) => ({
                            value: String(i),
                            label: (
                                <span className="font-mono text-xs">
                                    @{e.channel.username}
                                </span>
                            ),
                        }))}
                    />
                )}
            </div>

            {current ? (
                tab === 'preview' ? (
                    <div className="surface p-5 sm:p-7">
                        <ChannelSection section={current.preview} />
                    </div>
                ) : (
                    <SectionEditor
                        editor={current}
                        baseUrl="/dashboard/summary"
                        regenerationsLeft={regenerationsLeft}
                    />
                )
            ) : (
                <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-5">
                    <Glyph name="empty" className="size-10" />
                    <p className="text-sm text-muted-foreground">
                        No verified channel yet.
                    </p>
                </div>
            )}

            <div className="sticky bottom-0 z-20 -mx-5 mt-10 border-t border-border/70 bg-background/85 px-5 py-4 backdrop-blur-md">
                <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                    <Form action="/onboarding/submit" method="post">
                        {({ processing, errors }) => (
                            <>
                                <Button
                                    size="lg"
                                    variant={
                                        creator.status === 'pending_review'
                                            ? 'secondary'
                                            : 'brand'
                                    }
                                    type="submit"
                                    disabled={
                                        processing ||
                                        creator.status === 'pending_review'
                                    }
                                >
                                    <Send />
                                    {creator.status === 'pending_review'
                                        ? 'Submitted'
                                        : 'Submit for review'}
                                </Button>
                                <InputError message={errors.submit} />
                            </>
                        )}
                    </Form>
                    <Link
                        href={`/${creator.slug}`}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        Full-page preview
                        <ArrowUpRight className="size-3.5" />
                    </Link>
                </div>
            </div>
        </>
    );
}
