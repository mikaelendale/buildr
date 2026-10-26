import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            telegramBot: string | null;
            supportTelegram: string | null;
            maker: {
                handle: string;
                url: string;
                links: { platform: string; label: string; url: string }[];
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
