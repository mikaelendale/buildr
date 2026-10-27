import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    sent: boolean;
    email: string | null;
};

/**
 * The reset link goes to the account's Telegram, through the bot. The page
 * never says whether the account exists or is connected.
 */
export default function ForgotPassword({ sent, email }: Props) {
    const { telegramBot, supportTelegram } = usePage().props;

    return (
        <>
            <Head title="Reset your password" />

            <div className="flex flex-col gap-6">
                {sent && (
                    <div className="grid gap-3 rounded-xl bg-mint-soft p-4 text-sm text-pretty ring-1 ring-mint/15">
                        <p>
                            If <b className="font-medium">{email}</b> has
                            Telegram connected, your reset link is waiting in{' '}
                            {telegramBot ? (
                                <span className="font-mono">
                                    @{telegramBot}
                                </span>
                            ) : (
                                'our bot'
                            )}
                            .
                        </p>
                        {telegramBot && (
                            <Button
                                asChild
                                size="sm"
                                className="justify-self-start"
                            >
                                <a
                                    href={`https://t.me/${telegramBot}`}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Send />
                                    Open @{telegramBot}
                                </a>
                            </Button>
                        )}
                    </div>
                )}

                <Form
                    action="/forgot-password"
                    method="post"
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    defaultValue={email ?? ''}
                                    placeholder="you@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {sent ? 'Send it again' : 'Send reset link'}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="space-y-2 text-center text-xs text-pretty text-muted-foreground">
                    <p>
                        Already in the bot? Send{' '}
                        <code className="rounded-md bg-secondary px-1.5 py-0.5 font-mono text-foreground">
                            /reset
                        </code>{' '}
                        there.
                    </p>
                    <p>
                        Never connected Telegram?{' '}
                        {supportTelegram ? (
                            <a
                                href={`https://t.me/${supportTelegram}`}
                                target="_blank"
                                rel="noopener"
                                className="text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                            >
                                Message us
                            </a>
                        ) : (
                            'Message the Buildr team'
                        )}{' '}
                        and we&apos;ll help.
                    </p>
                    <p>
                        <Link
                            href="/login"
                            className="text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                        >
                            Back to log in
                        </Link>
                    </p>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Reset your password',
    description: 'We send the link to your Telegram, through our bot.',
};
