export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-1'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-[15px] font-medium tracking-tight'
                        : 'text-[22px] font-light tracking-[-0.02em]'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="text-sm text-pretty text-muted-foreground">
                    {description}
                </p>
            )}
        </header>
    );
}
