import { Link } from '@inertiajs/react';
import ProsePage from '@/components/prose-page';

export default function Privacy() {
    return (
        <ProsePage
            title="Privacy"
            updated="October 2026"
            description="What Buildr collects, what it never touches, and how to remove your data."
        >
            <h2>What we read</h2>
            <ul>
                <li>
                    Only the <strong>public web previews</strong> of Telegram
                    channels (t.me/channel and t.me/s/channel), the same pages
                    anyone can open in a browser.
                </li>
                <li>
                    We never use bots inside channels, never log in as anyone,
                    and never read private chats, groups or DMs.
                </li>
            </ul>

            <h2>What we collect when you log in</h2>
            <ul>
                <li>
                    The fields Telegram&apos;s Login Widget shares: your
                    Telegram ID, name, username and profile photo.
                </li>
                <li>
                    Anything you choose to add to your profile (real name, bio,
                    socials, location).
                </li>
                <li>
                    Whether our bot may send you messages (only if you allow
                    it).
                </li>
            </ul>

            <h2>How profiles are written</h2>
            <ul>
                <li>
                    An AI model reads public posts and writes a summary. Every
                    factual claim must cite the post it came from, and a second
                    check drops anything unsupported.
                </li>
                <li>
                    Sensitive topics are filtered out: health, family and
                    relationships, religion, politics, finances, precise
                    location and appearance.
                </li>
                <li>
                    Unclaimed pages never name or profile the person behind a
                    channel.
                </li>
            </ul>

            <h2>Analytics</h2>
            <p>
                We count profile views. We don&apos;t store IP addresses: unique
                visits are counted with a one-way hash that changes every day.
            </p>

            <h2>Removal</h2>
            <p>
                You can remove your channel any time at{' '}
                <Link href="/remove">/remove</Link>, or report a page. Removed
                channels go on a blocklist and are never read again. Deleting
                your account removes your profile, overrides and socials.
            </p>
        </ProsePage>
    );
}
