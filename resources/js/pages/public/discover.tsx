import { Head } from '@inertiajs/react';
import Display from '@/components/brand/display';
import DiscoverGrid from '@/components/directory/discover-grid';
import SearchBox from '@/components/directory/search-box';
import type { DiscoverFilters } from '@/components/directory/discover-grid';
import type { CursorPage, ProfileCard, TagChip } from '@/types';

type Props = {
    filters: DiscoverFilters;
    entries: CursorPage<ProfileCard>;
    tags: TagChip[];
};

export default function Discover({ filters, entries, tags }: Props) {
    return (
        <>
            <Head title="Discover builders">
                <meta
                    head-key="description"
                    name="description"
                    content="Browse Telegram builders and creators by tag, language, size and activity."
                />
            </Head>

            <div className="mx-auto max-w-[1080px] px-5 pt-10 sm:pt-14">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <Display as="h1" size="lg" lead="Discover">
                            builders on Telegram.
                        </Display>
                        <p className="mt-3 max-w-md text-muted-foreground">
                            Filter by topic, language and size. Verified pages
                            are run by the creators themselves.
                        </p>
                    </div>
                    <SearchBox size="md" className="lg:max-w-sm" />
                </div>

                <div className="mt-8">
                    <DiscoverGrid
                        path="/discover"
                        filters={filters}
                        entries={entries}
                        tags={tags}
                    />
                </div>
            </div>
        </>
    );
}
