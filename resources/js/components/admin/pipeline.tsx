import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type Pipeline = {
    stage: string;
    tone: 'sky' | 'mint' | 'amber' | 'rose' | 'violet' | 'outline';
    detail: string | null;
    progress: { done: number; total: number } | null;
    /** A job for this channel is running right now. */
    active: boolean;
    queued: number;
    attempts: number;
    posts: number;
    last_ai: {
        job: string;
        model: string;
        status: string;
        at: string | null;
    } | null;
};

/**
 * A channel's pipeline stage: the stage pill (with a live dot while a job
 * runs), what it's doing, and a thin progress bar while labelling.
 */
export function PipelineStage({
    pipeline,
    className,
}: {
    pipeline: Pipeline | null | undefined;
    className?: string;
}) {
    if (!pipeline) {
        return null;
    }

    const percent = pipeline.progress
        ? Math.round(
              (pipeline.progress.done / Math.max(1, pipeline.progress.total)) *
                  100,
          )
        : null;

    return (
        <div className={cn('min-w-36 space-y-1', className)}>
            <Badge
                variant={pipeline.tone}
                className={cn(
                    'gap-1.5',
                    pipeline.tone === 'outline' && 'text-muted-foreground',
                )}
            >
                {pipeline.active && (
                    <span className="relative flex size-1.5">
                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:animate-none" />
                        <span className="relative inline-flex size-1.5 rounded-full bg-current" />
                    </span>
                )}
                {pipeline.stage}
            </Badge>
            {percent !== null && (
                <div
                    className="h-1 w-full max-w-40 overflow-hidden rounded-full bg-secondary"
                    role="progressbar"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                >
                    <div
                        className="h-full rounded-full bg-sky transition-[width] duration-500"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            )}
            {pipeline.detail && (
                <p className="max-w-56 truncate font-mono text-[11px] text-muted-foreground">
                    {pipeline.detail}
                </p>
            )}
        </div>
    );
}

/**
 * Re-fetch these props every few seconds while something is moving.
 */
export function useLiveReload(active: boolean, only: string[], ms = 4000) {
    const { start, stop } = usePoll(
        ms,
        { only },
        { autoStart: false, keepAlive: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }

        return stop;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active]);
}
