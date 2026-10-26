import { Link } from '@inertiajs/react';
import ProsePage from '@/components/prose-page';

export default function About() {
    return (
        <ProsePage
            title="About Buildr"
            description="One place to search any Telegram creator and see who runs the channel, what they build, and their journey."
        >
            <p>
                Telegram is full of people building in public: apps, bots,
                courses, communities. But a channel name rarely tells you who is
                behind it, what they have shipped, or where else to find them.
            </p>
            <p>
                Buildr is one place to search any Telegram creator. Each profile
                is generated from the channel&apos;s <strong>public</strong>{' '}
                posts: projects, eras, posting habits, and the best posts to
                start with. Every factual claim links back to the post it came
                from.
            </p>

            <h2>Two kinds of pages</h2>
            <ul>
                <li>
                    <strong>Claimed profiles</strong> belong to a creator who
                    proved they own the channel. They add their name, socials
                    and bio, and control what the page says.
                </li>
                <li>
                    <strong>Unclaimed channel pages</strong> only describe the
                    channel. They never name or profile the person behind it.
                </li>
            </ul>

            <h2>You stay in control</h2>
            <p>
                Run a channel? <Link href="/claim">Claim your page</Link> to
                edit or hide anything, or{' '}
                <Link href="/remove">remove your channel</Link> entirely.
                Removed channels are never read again.
            </p>
        </ProsePage>
    );
}
