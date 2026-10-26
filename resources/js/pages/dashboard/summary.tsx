import { useState } from 'react';
import SectionEditor from '@/components/creator/section-editor';
import type { ChannelEditor } from '@/components/creator/section-editor';
import Glyph from '@/components/figures/glyph';
import PageContainer from '@/components/page-container';
import Segmented from '@/components/ui/segmented';

export default function SummaryPage({
    editor,
    regenerationsLeft,
}: {
    editor: ChannelEditor[];
    regenerationsLeft: number;
}) {
    const [index, setIndex] = useState(0);
    const current = editor[index];

    return (
        <PageContainer
            title="AI summary"
            description="Everything here comes from your public posts. Edits are kept even when the AI rewrites your profile."
        >
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

            {current ? (
                <SectionEditor
                    editor={current}
                    baseUrl="/dashboard/summary"
                    regenerationsLeft={regenerationsLeft}
                />
            ) : (
                <div className="group flex items-center gap-4 rounded-2xl border border-dashed border-border p-5">
                    <Glyph name="spark" className="size-10" />
                    <p className="text-sm text-muted-foreground">
                        Verify a channel to get your AI summary.
                    </p>
                </div>
            )}
        </PageContainer>
    );
}

SummaryPage.layout = {
    breadcrumbs: [{ title: 'AI summary', href: '/dashboard/summary' }],
};
