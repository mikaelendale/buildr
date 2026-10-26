import { Link, usePage } from '@inertiajs/react';
import ProsePage from '@/components/prose-page';

export default function Takedown() {
    const { supportTelegram } = usePage().props;

    return (
        <ProsePage title="Takedown policy" updated="October 2026">
            <p>
                We respond to every takedown request within{' '}
                <strong>72 hours</strong>.
            </p>

            <h2>If it&apos;s your channel</h2>
            <p>
                The fastest route is self-service: go to{' '}
                <Link href="/remove">/remove</Link>, log in with Telegram, and
                post a short code in your channel description to prove it&apos;s
                yours. The page, its posts and summaries are deleted immediately
                and the channel is added to our blocklist so it&apos;s never
                read again.
            </p>

            <h2>If it&apos;s about you, but not your channel</h2>
            <p>
                Use the <strong>Report</strong> button at the bottom of any
                profile and pick &ldquo;Privacy concern&rdquo;,
                &ldquo;Impersonation&rdquo; or &ldquo;Wrong information&rdquo;.
                Leave a way to reach you if you&apos;re not logged in.
            </p>

            <h2>What we do</h2>
            <ul>
                <li>Wrong or unsupported claims are removed or corrected.</li>
                <li>
                    Impersonation leads to the profile being removed and the
                    account banned.
                </li>
                <li>
                    Privacy issues (personal details, sensitive topics) are
                    removed and the source posts excluded.
                </li>
            </ul>

            {supportTelegram && (
                <p>
                    You can also contact us on Telegram:{' '}
                    <a href={`https://t.me/${supportTelegram}`}>
                        @{supportTelegram}
                    </a>
                    .
                </p>
            )}
        </ProsePage>
    );
}
