import { Head } from '@inertiajs/react';

export default function ProsePage({
    title,
    description,
    updated,
    children,
}: {
    title: string;
    description?: string;
    updated?: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <Head title={title}>
                {description && (
                    <meta
                        head-key="description"
                        name="description"
                        content={description}
                    />
                )}
            </Head>
            <article className="mx-auto max-w-[680px] px-5 pt-14 sm:pt-20">
                {updated && <p className="eyebrow">Last updated {updated}</p>}
                <h1 className="mt-2 text-[34px] leading-tight font-light tracking-[-0.03em] sm:text-[40px]">
                    {title}
                </h1>
                <div className="mt-10 space-y-5 text-[15px] leading-relaxed text-pretty text-foreground/80 [&_a]:text-foreground [&_a]:underline [&_a]:decoration-border [&_a]:underline-offset-4 hover:[&_a]:decoration-foreground [&_h2]:mt-12 [&_h2]:text-xl [&_h2]:font-normal [&_h2]:tracking-tight [&_h2]:text-foreground [&_li]:ml-5 [&_li]:list-disc [&_li]:marker:text-ink-soft [&_strong]:font-medium [&_strong]:text-foreground [&_ul]:space-y-1.5">
                    {children}
                </div>
            </article>
        </>
    );
}
