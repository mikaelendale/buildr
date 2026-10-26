import { Link } from '@inertiajs/react';
import ProsePage from '@/components/prose-page';

export default function Terms() {
    return (
        <ProsePage title="Terms of use" updated="October 2026">
            <p>
                By using Buildr you agree to these terms. If you don&apos;t
                agree, please don&apos;t use the site.
            </p>

            <h2>Profiles and content</h2>
            <ul>
                <li>
                    Profiles are generated from public Telegram posts and may
                    contain mistakes. Every claim links to its source so you can
                    check it.
                </li>
                <li>
                    Creators who claim a page are responsible for what they add
                    to it (names, bio, socials, edits).
                </li>
                <li>
                    Don&apos;t claim channels you don&apos;t own or impersonate
                    anyone. We remove pages and ban accounts that do.
                </li>
            </ul>

            <h2>Accounts</h2>
            <ul>
                <li>
                    Creators log in with Telegram. You can delete your account
                    at any time from your dashboard settings.
                </li>
                <li>
                    We may hide profiles or suspend accounts that break these
                    terms, spam, or harass others.
                </li>
            </ul>

            <h2>Takedowns</h2>
            <p>
                See the <Link href="/takedown-policy">takedown policy</Link>.
                Channel owners can also remove their channel themselves at{' '}
                <Link href="/remove">/remove</Link>.
            </p>

            <h2>No warranty</h2>
            <p>
                Buildr is provided as is. We aren&apos;t affiliated with
                Telegram.
            </p>
        </ProsePage>
    );
}
