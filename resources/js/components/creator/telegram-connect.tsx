import { Check, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

export type TelegramLink = { connected: boolean; username: string | null };

/**
 * Connection state for the bot. After the person opens Telegram, it checks
 * every few seconds (and the moment they come back to the tab) until the
 * bot confirms, for up to 30 minutes, as long as the link is valid.
 */
export function useTelegramLink(initial: TelegramLink) {
    const [link, setLink] = useState<TelegramLink>(initial);
    const [waiting, setWaiting] = useState(false);

    useEffect(() => {
        if (!waiting || link.connected) {
            return;
        }

        const check = async () => {
            try {
                const response = await fetch('/api/telegram/status', {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                const status = (await response.json()) as TelegramLink;

                if (status.connected) {
                    setLink({ connected: true, username: status.username });
                    setWaiting(false);
                }
            } catch {
                // Offline for a moment; the next tick tries again.
            }
        };

        const timer = window.setInterval(() => void check(), 2500);
        const giveUp = window.setTimeout(
            () => setWaiting(false),
            30 * 60 * 1000,
        );
        window.addEventListener('focus', check);

        return () => {
            window.clearInterval(timer);
            window.clearTimeout(giveUp);
            window.removeEventListener('focus', check);
        };
    }, [waiting, link.connected]);

    return { ...link, waiting, start: () => setWaiting(true) };
}

/**
 * Opens Telegram on the bot with a one-time link (a new tab on desktop,
 * the app on phones), then waits for the bot to confirm.
 */
export function ConnectTelegramButton({
    waiting,
    onStart,
    size = 'default',
    variant = 'default',
    className,
}: {
    waiting: boolean;
    onStart: () => void;
    size?: 'default' | 'sm' | 'lg';
    variant?: 'default' | 'outline';
    className?: string;
}) {
    return (
        <Button asChild size={size} variant={variant} className={className}>
            <a
                href="/telegram/connect"
                target="_blank"
                rel="noopener"
                onClick={onStart}
            >
                {waiting ? <Spinner /> : <Send />}
                {waiting ? 'Waiting for Telegram…' : 'Connect Telegram'}
            </a>
        </Button>
    );
}

/** "Connected as @username" in the mint status pill. */
export function TelegramConnected({
    username,
    className,
}: {
    username: string | null;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full bg-mint-soft px-2.5 py-1 text-xs font-medium text-mint ring-1 ring-mint/15',
                className,
            )}
        >
            <Check className="size-3.5" />
            {username ? `Connected as @${username}` : 'Telegram connected'}
        </span>
    );
}
