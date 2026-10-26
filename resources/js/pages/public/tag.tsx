import { Head } from '@inertiajs/react';
import Display from '@/components/brand/display';
import DiscoverGrid from '@/components/directory/discover-grid';
import type { DiscoverFilters } from '@/components/directory/discover-grid';
import type { CursorPage, ProfileCard, TagChip } from '@/types';

type Props = {
    tag: TagChip;
    filters: DiscoverFilters;
    entries: CursorPage<ProfileCard>;
    tags: TagChip[];
};

export default function TagPage({ tag, filters, entries, tags }: Props) {
    return (
        <>
            <Head title={`${tag.name} builders`}>
                <meta
                    head-key="description"
                    name="description"
                    content={
                        tag.description ??
                        `Telegram builders and channels tagged ${tag.name}.`
                    }
                />
            </Head>

            <div className="mx-auto max-w-[1080px] px-5 pt-10 sm:pt-14">
                <div className="eyebrow">Topic</div>
                <Display
                    as="h1"
                    size="lg"
                    lead={`#${tag.name}`}
                    className="mt-2"
                >
                    builders.
                </Display>
                {tag.description && (
                    <p className="mt-3 max-w-xl text-muted-foreground">
                        {tag.description}
                    </p>
                )}

                <div className="mt-8">
                    <DiscoverGrid
                        path={`/tags/${tag.slug}`}
                        filters={filters}
                        entries={entries}
                        tags={tags}
                        lockedTag={tag.slug}
                    />
                </div>
            </div>
        </>
    );
}
