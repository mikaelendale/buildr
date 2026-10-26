import { Form, Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Segmented from '@/components/ui/segmented';
import { Spinner } from '@/components/ui/spinner';

type Mode = 'login' | 'register';

type Props = {
    mode: Mode;
    devLogin: boolean;
    status?: string;
    support?: string | null;
};

const PROMISES = [
    'An account is just your email and a password. Nothing to install.',
    'You prove a channel is yours with a short code in its bio. We never get access to it.',
];

export default function CreatorLogin({
    mode: initialMode,
    devLogin,
    status,
    support,
}: Props) {
    const [mode, setMode] = useState<Mode>(initialMode);
    const register = mode === 'register';

    return (
        <>
            <Head title={register ? 'Create your account' : 'Log in'} />

            <div className="flex flex-col gap-6">
                {status && (
                    <p className="w-full rounded-xl bg-mint-soft px-3 py-2 text-center text-sm text-mint ring-1 ring-mint/15">
                        {status}
                    </p>
                )}

                <Segmented
                    label="Log in or create an account"
                    className="self-center"
                    value={mode}
                    onChange={(value) => setMode(value as Mode)}
                    items={[
                        { value: 'login', label: 'Log in' },
                        { value: 'register', label: 'Create account' },
                    ]}
                />

                <Form
                    key={mode}
                    action={register ? '/register' : '/login'}
                    method="post"
                    resetOnError={['password']}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            {register && (
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="name"
                                        placeholder="Selam Tesfaye"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus={!register}
                                    autoComplete="email"
                                    placeholder="you@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete={
                                        register
                                            ? 'new-password'
                                            : 'current-password'
                                    }
                                    placeholder={
                                        register
                                            ? 'At least 8 characters'
                                            : 'Your password'
                                    }
                                />
                                <InputError message={errors.password} />
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="mt-1 w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {register ? 'Create account' : 'Log in'}
                            </Button>
                        </>
                    )}
                </Form>

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

                {!register && (
                    <p className="text-center text-xs text-muted-foreground">
                        Forgot your password?{' '}
                        {support ? (
                            <a
                                href={`https://t.me/${support}`}
                                target="_blank"
                                rel="noopener"
                                className="text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                            >
                                Message us
                            </a>
                        ) : (
                            'Message the Buildr team'
                        )}{' '}
                        and we&apos;ll reset it.
                    </p>
                )}

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
                                    Dev login (test creator)
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

CreatorLogin.layout = {
    title: 'Welcome to Buildr',
    description: 'Log in or create an account to claim your channel.',
};
