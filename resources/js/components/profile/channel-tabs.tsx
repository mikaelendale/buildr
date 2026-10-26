import { useState } from 'react';
import ChannelSection from '@/components/profile/channel-section';
import Segmented from '@/components/ui/segmented';
import type { ChannelSection as Section } from '@/types';

/**
 * One section per channel; several channels get a switcher, primary first (plan §9.4).
 */
export default function ChannelTabs({
    sections,
    showHowTheyWork = true,
    withNav = false,
    actions,
}: {
    sections: Section[];
    showHowTheyWork?: boolean;
    /** Sticky in-page section nav (public profile pages). */
    withNav?: boolean;
    actions?: React.ReactNode;
}) {
    const [active, setActive] = useState(0);
    const current = sections[active] ?? sections[0];

    if (!current) {
        return null;
    }

    return (
        <div>
            {sections.length > 1 && (
                <Segmented
                    label="Channels"
                    className="mb-6"
                    value={String(active)}
                    onChange={(v) => setActive(Number(v))}
                    items={sections.map((section, i) => ({
                        value: String(i),
                        label: `@${section.channel.username}`,
                    }))}
                />
            )}

            <ChannelSection
                key={current.channel.username}
                section={current}
                showHowTheyWork={showHowTheyWork}
                withNav={withNav}
                actions={actions}
            />
        </div>
    );
}
