import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowUpRight, Check } from 'lucide-react';
import TelegramLoginButton from '@/components/creator/telegram-login-button';
import Glyph from '@/components/figures/glyph';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

type Props = {
    botUsername: string | null;
    devLogin: boolean;
    status?: string;
};

const PROMISES = [
    'No passwords, no account access. We only get your public Telegram name, username and photo.',
    'Allowing messages lets our bot tell you when your profile is ready. You can stop it any time.',
];

export default function TelegramLogin({
    botUsername,
    devLogin,
    status,
}: Props) {
    const { errors } = usePage().props as { errors?: Record<string, string> };

    return (
        <>
            <Head title="Log in" />

            <div className="flex flex-col items-center gap-6 text-center">
                {status && (
                    <p className="w-full rounded-xl bg-mint-soft px-3 py-2 text-sm text-mint ring-1 ring-mint/15">
                        {status}
                    </p>
                )}

                {botUsername ? (
                    <TelegramLoginButton botUsername={botUsername} />
                ) : (
                    <div className="group flex w-full flex-col items-center gap-3 rounded-2xl border border-dashed border-border p-5">
                        <Glyph name="send" className="size-10" />
                        <p className="text-sm text-pretty text-muted-foreground">
                            Telegram login isn&apos;t configured yet. Set{' '}
                            <code className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-[11px] text-foreground/80">
                                TELEGRAM_BOT_USERNAME
                            </code>
                            .
                        </p>
                    </div>
                )}

                <InputError message={errors?.telegram} />

                <ul className="w-full space-y-2.5 text-left text-sm text-muted-foreground">
                    {PROMISES.map((line) => (
                        <li key={line} className="flex gap-3">
                            <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-mint-soft ring-1 ring-mint/15">
                                <Check className="size-3 text-mint" />
                            </span>
                            <span className="text-pretty">{line}</span>
                        </li>
                    ))}
                </ul>

                <p className="text-xs text-muted-foreground">
                    Button not showing up inside Telegram?{' '}
                    <a
                        href={
                            typeof window !== 'undefined'
                                ? window.location.href
                                : '/login'
                        }
                        target="_blank"
                        rel="noopener"
                        className="inline-flex items-center gap-0.5 text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                    >
                        Open this page in your browser
                        <ArrowUpRight className="size-3" />
                    </a>
                </p>

                {devLogin && (
                    <Form
                        action="/auth/dev-login"
                        method="post"
                        className="w-full border-t border-border/70 pt-5"
                    >
                        {({ processing }) => (
                            <>
                                <p className="eyebrow mb-2.5">Local only</p>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    Dev login (fake Telegram user)
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

TelegramLogin.layout = {
    title: 'Log in with Telegram',
    description: 'Creators sign in with Telegram. It takes one tap.',
};
