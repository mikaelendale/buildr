import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Bell, KeyRound, Sparkles } from 'lucide-react';
import {
    ConnectTelegramButton,
    TelegramConnected,
    useTelegramLink,
} from '@/components/creator/telegram-connect';
import { Button } from '@/components/ui/button';

type Props = {
    next: string;
    connected: boolean;
    username: string | null;
};

const PERKS = [
    {
        icon: KeyRound,
        text: 'Forgot your password? Send /reset and the bot hands you a link.',
    },
    {
        icon: Bell,
        text: 'Hear the moment your page is ready, a channel is verified, or you go live.',
    },
    {
        icon: Sparkles,
        text: 'A short note when your profile picks up new posts.',
    },
];

/**
 * Right after signing in: connect the bot (it's where password resets and
 * updates go; there is no email), or skip for now.
 */
export default function ConnectTelegram({ next, connected, username }: Props) {
    const { telegramBot } = usePage().props;
    const link = useTelegramLink({ connected, username });

    return (
        <>
            <Head title="Connect Telegram" />

            <div className="flex flex-col gap-6">
                <ul className="space-y-3 text-sm text-muted-foreground">
                    {PERKS.map(({ icon: Icon, text }) => (
                        <li key={text} className="flex gap-3">
                            <span className="mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-mint-soft ring-1 ring-mint/15">
                                <Icon className="size-3.5 text-mint" />
                            </span>
                            <span className="text-pretty">{text}</span>
                        </li>
                    ))}
                </ul>

                {link.connected ? (
                    <div className="grid gap-3">
                        <TelegramConnected
                            username={link.username}
                            className="self-center"
                        />
                        <Button asChild size="lg" className="w-full">
                            <Link href={next}>
                                Continue
                                <ArrowRight />
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <ConnectTelegramButton
                            size="lg"
                            className="w-full"
                            waiting={link.waiting}
                            onStart={link.start}
                        />
                        {link.waiting && (
                            <p className="text-center text-xs text-pretty text-muted-foreground">
                                Press <b className="text-foreground">Start</b>{' '}
                                in{' '}
                                <span className="font-mono">
                                    {telegramBot
                                        ? `@${telegramBot}`
                                        : 'the bot'}
                                </span>
                                , then come back here.
                            </p>
                        )}
                        <Form action="/connect-telegram/skip" method="post">
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    className="w-full text-muted-foreground"
                                    disabled={processing}
                                >
                                    Not now
                                </Button>
                            )}
                        </Form>
                    </div>
                )}

                <p className="text-center text-xs text-pretty text-ink-soft">
                    We only message you about your account. Send /stop to the
                    bot any time.
                </p>
            </div>
        </>
    );
}

ConnectTelegram.layout = {
    title: 'Get Buildr on Telegram',
    description:
        'There’s no email: our bot is how you reset your password and hear about your page.',
};
