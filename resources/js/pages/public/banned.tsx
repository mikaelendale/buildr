import { Head, Link } from '@inertiajs/react';
import StatusPage from '@/components/status-page';

export default function Banned({ reason }: { reason: string | null }) {
    return (
        <>
            <Head title="Account suspended" />
            <StatusPage
                glyph="shield"
                eyebrow="Suspended"
                title="Your account is suspended"
            >
                {reason && (
                    <p className="mx-auto mt-1 w-fit rounded-xl bg-rose-soft px-3 py-2 text-sm text-rose ring-1 ring-rose/15">
                        {reason}
                    </p>
                )}
                <p className="mt-4 text-sm">
                    If you think this is a mistake, see the{' '}
                    <Link
                        href="/takedown-policy"
                        className="text-foreground underline underline-offset-4"
                    >
                        takedown policy
                    </Link>{' '}
                    for how to reach us.
                </p>
            </StatusPage>
        </>
    );
}
