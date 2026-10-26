import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const LABELS: Record<string, string> = {
    welcome: 'Welcome',
    channel: 'Channel',
    verify: 'Verify',
    basics: 'Basics',
    building: 'Building',
    review: 'Review',
};

/**
 * Step indicator + light headline for each onboarding screen. Finished steps
 * are ink, the current one carries the spectrum, the rest wait in grey.
 */
export default function OnboardingHeader({
    step,
    steps,
    title,
    description,
}: {
    step: number;
    steps: string[];
    title: string;
    description?: React.ReactNode;
}) {
    return (
        <div className="mb-10 space-y-8">
            <Head title={title} />

            <div>
                <div className="mb-3 flex items-baseline justify-between gap-3">
                    <span className="eyebrow">
                        Step {String(step).padStart(2, '0')} /{' '}
                        {String(steps.length).padStart(2, '0')}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {LABELS[steps[step - 1]] ?? ''}
                    </span>
                </div>
                <ol
                    className="grid gap-1.5"
                    style={{
                        gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))`,
                    }}
                    role="progressbar"
                    aria-valuemin={1}
                    aria-valuemax={steps.length}
                    aria-valuenow={step}
                    aria-label="Onboarding progress"
                >
                    {steps.map((name, i) => {
                        const done = i < step - 1;
                        const current = i === step - 1;

                        return (
                            <li key={name} className="min-w-0">
                                <div
                                    className={cn(
                                        'h-1 rounded-full transition-colors duration-300',
                                        done && 'bg-foreground/80',
                                        current && 'bg-spectrum',
                                        !done && !current && 'bg-border',
                                    )}
                                />
                                <div
                                    className={cn(
                                        'mt-2 hidden items-center gap-1 truncate text-[11px] sm:flex',
                                        current
                                            ? 'text-foreground'
                                            : 'text-ink-soft',
                                    )}
                                >
                                    {done && <Check className="size-3" />}
                                    {LABELS[name] ?? name}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </div>

            <div>
                <h1 className="text-[30px] leading-[1.12] font-light tracking-[-0.03em] text-balance sm:text-[38px]">
                    {title}
                </h1>
                {description && (
                    <p className="mt-3 max-w-lg text-[15px] leading-relaxed text-pretty text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
        </div>
    );
}
