import { Head, Link } from '@inertiajs/react';
import StatusPage from '@/components/status-page';
import { Button } from '@/components/ui/button';

export default function Removed({ username }: { username: string }) {
    return (
        <>
            <Head title="Channel removed">
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>
            <StatusPage
                glyph="empty"
                eyebrow="Removed"
                title={<>@{username} was removed</>}
            >
                <p>
                    The owner asked us to remove this channel. We don&apos;t
                    read or show its posts anymore.
                </p>
                <Button asChild variant="outline" className="mt-6">
                    <Link href="/discover">Discover other builders</Link>
                </Button>
            </StatusPage>
        </>
    );
}
