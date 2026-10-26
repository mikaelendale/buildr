import { Head } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Dashboard and admin page frame: a light headline with an optional muted
 * description, actions on the right, then the page on the grey canvas.
 */
export default function PageContainer({
    title,
    description,
    actions,
    children,
    wide = false,
}: {
    title: string;
    description?: string;
    actions?: React.ReactNode;
    children: React.ReactNode;
    wide?: boolean;
}) {
    return (
        <>
            <Head title={title} />
            <div
                className={cn(
                    'mx-auto w-full space-y-6 px-5 pt-8 pb-16 sm:px-8 sm:pt-10',
                    wide ? 'max-w-[1240px]' : 'max-w-[960px]',
                )}
            >
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="text-[28px] leading-tight font-light tracking-[-0.03em]">
                            {title}
                        </h1>
                        {description && (
                            <p className="mt-1.5 max-w-xl text-sm text-pretty text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex flex-wrap items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
                {children}
            </div>
        </>
    );
}
