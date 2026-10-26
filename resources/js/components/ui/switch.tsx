import { cn } from '@/lib/utils';

/**
 * A plain on/off switch (role="switch"), mint when on.
 */
export function Switch({
    checked,
    onCheckedChange,
    className,
    ...props
}: Omit<React.ComponentProps<'button'>, 'onChange'> & {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            data-state={checked ? 'checked' : 'unchecked'}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-10 shrink-0 cursor-pointer items-center rounded-full p-0.5 transition-colors duration-200 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-brand-ink' : 'bg-input',
                className,
            )}
            {...props}
        >
            <span
                className={cn(
                    'size-5 rounded-full bg-card shadow-[0_1px_2px_oklch(0_0_0/0.2)] transition-transform duration-200 ease-out',
                    checked ? 'translate-x-4' : 'translate-x-0',
                )}
            />
        </button>
    );
}
