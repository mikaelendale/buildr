import { Head, Link } from '@inertiajs/react';
import SearchBox from '@/components/directory/search-box';
import type { GlyphName } from '@/components/figures/glyph';
import StatusPage from '@/components/status-page';
import { Button } from '@/components/ui/button';

const MESSAGES: Record<
    number,
    { title: string; body: string; glyph: GlyphName }
> = {
    403: {
        title: 'Not allowed',
        body: "You don't have access to this page.",
        glyph: 'shield',
    },
    404: {
        title: "This page doesn't exist",
        body: 'Maybe the person you are looking for is under a different name.',
        glyph: 'search',
    },
    419: {
        title: 'Page expired',
        body: 'Refresh the page and try again.',
        glyph: 'clock',
    },
    429: {
        title: 'Slow down a little',
        body: 'Too many requests. Try again in a minute.',
        glyph: 'clock',
    },
    500: {
        title: 'Something broke',
        body: "We've been notified and are looking into it.",
        glyph: 'empty',
    },
    503: {
        title: 'Back soon',
        body: 'Buildr is down for a quick update.',
        glyph: 'clock',
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const message = MESSAGES[status] ?? MESSAGES[500];

    return (
        <>
            <Head title={message.title}>
                <meta head-key="robots" name="robots" content="noindex" />
            </Head>
            <StatusPage
                glyph={message.glyph}
                eyebrow={`Error ${status}`}
                title={message.title}
            >
                <p>{message.body}</p>
                {status === 404 && (
                    <SearchBox size="md" className="mt-8 text-left" />
                )}
                <Button asChild variant="outline" className="mt-6">
                    <Link href="/">Back to home</Link>
                </Button>
            </StatusPage>
        </>
    );
}
