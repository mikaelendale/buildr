import { Head } from '@inertiajs/react';
import type { Seo as SeoProps } from '@/types';

/**
 * Title, description, canonical, OG/Twitter tags and JSON-LD (plan §9.4, §11).
 * Rendered server-side through Inertia SSR so crawlers and Telegram previews see it.
 */
export default function Seo({ seo }: { seo: SeoProps }) {
    const appName = import.meta.env.VITE_APP_NAME || 'Buildr';
    const fullTitle = `${seo.title} | ${appName}`;

    return (
        <Head>
            <title>{seo.title}</title>
            <meta
                head-key="description"
                name="description"
                content={seo.description}
            />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            {seo.noindex && (
                <meta head-key="robots" name="robots" content="noindex" />
            )}
            <meta head-key="og:type" property="og:type" content="profile" />
            <meta head-key="og:title" property="og:title" content={fullTitle} />
            <meta
                head-key="og:description"
                property="og:description"
                content={seo.description}
            />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            {seo.image && (
                <meta
                    head-key="og:image"
                    property="og:image"
                    content={seo.image}
                />
            )}
            {seo.image && (
                <meta
                    head-key="og:image:width"
                    property="og:image:width"
                    content="1200"
                />
            )}
            {seo.image && (
                <meta
                    head-key="og:image:height"
                    property="og:image:height"
                    content="630"
                />
            )}
            <meta
                head-key="twitter:card"
                name="twitter:card"
                content="summary_large_image"
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content={fullTitle}
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content={seo.description}
            />
            {seo.image && (
                <meta
                    head-key="twitter:image"
                    name="twitter:image"
                    content={seo.image}
                />
            )}
            {seo.jsonLd && (
                <script
                    head-key="jsonld"
                    type="application/ld+json"
                    dangerouslySetInnerHTML={{
                        __html: JSON.stringify(seo.jsonLd).replace(
                            /</g,
                            '\\u003c',
                        ),
                    }}
                />
            )}
        </Head>
    );
}
