import { Fragment } from 'react';

const URL_PATTERN = /(https?:\/\/[^\s<]+[^\s<.,;:!?)"'])/g;

/**
 * Plain text with auto-linked URLs only (plan §12.1): no HTML from the
 * creator is ever rendered, and every link is nofollow/ugc.
 */
export default function Linkify({ text }: { text: string }) {
    const parts = text.split(URL_PATTERN);

    return (
        <>
            {parts.map((part, i) =>
                i % 2 === 1 ? (
                    <a
                        key={i}
                        href={part}
                        target="_blank"
                        rel="nofollow noopener ugc"
                        className="text-brand-ink underline-offset-2 hover:underline"
                    >
                        {part.replace(/^https?:\/\//, '')}
                    </a>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}
