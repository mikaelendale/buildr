import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

declare global {
    interface Window {
        onTelegramAuth?: (user: Record<string, string | number>) => void;
    }
}

/**
 * Telegram Login Widget (plan §5.1), web only: it asks for the public profile
 * and nothing else (no permission for the bot to message). The widget only
 * renders on the domain set with /setdomain.
 */
export default function TelegramLoginButton({
    botUsername,
}: {
    botUsername: string;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        window.onTelegramAuth = (user) => {
            router.post('/auth/telegram/callback', user);
        };

        const script = document.createElement('script');
        script.src = 'https://telegram.org/js/telegram-widget.js?22';
        script.async = true;
        script.setAttribute('data-telegram-login', botUsername);
        script.setAttribute('data-size', 'large');
        script.setAttribute('data-radius', '20');
        script.setAttribute('data-onauth', 'onTelegramAuth(user)');
        script.onload = () => setLoaded(true);

        const el = container.current;
        el?.appendChild(script);

        const timer = window.setTimeout(() => setLoaded(true), 4000);

        return () => {
            window.clearTimeout(timer);
            el?.replaceChildren();
            delete window.onTelegramAuth;
        };
    }, [botUsername]);

    return (
        <div className="flex flex-col items-center gap-3">
            <div
                ref={container}
                className="flex min-h-12 items-center justify-center"
            />
            {!loaded && (
                <div
                    className="h-11 w-56 animate-pulse rounded-full bg-secondary"
                    aria-label="Loading Telegram login"
                />
            )}
        </div>
    );
}
