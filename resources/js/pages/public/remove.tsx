import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import Display from '@/components/brand/display';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const WHAT_HAPPENS = [
    'The channel page, its posts, summaries and projects are deleted.',
    'The channel goes on our blocklist, so we never read it again.',
    "You'll get a confirmation message on Telegram.",
];

export default function Remove({ channel }: { channel: string | null }) {
    const { auth } = usePage().props;
    const form = useForm({ username: channel ? `@${channel}` : '' });

    return (
        <>
            <Head title="Remove my channel" />
            <div className="mx-auto max-w-[680px] px-5 pt-14 sm:pt-20">
                <Display as="h1" size="lg" lead="Remove" stack>
                    your channel.
                </Display>
                <p className="mt-4 max-w-md text-muted-foreground">
                    Don&apos;t want a page? Prove the channel is yours and we
                    remove it right away.
                </p>

                <ul className="mt-8 space-y-2.5">
                    {WHAT_HAPPENS.map((line) => (
                        <li
                            key={line}
                            className="flex items-start gap-3 text-sm text-foreground/80"
                        >
                            <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-card ring-1 ring-border/80">
                                <Check className="size-3 text-muted-foreground" />
                            </span>
                            {line}
                        </li>
                    ))}
                </ul>

                {auth.user ? (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/remove');
                        }}
                        className="surface mt-10 space-y-3 p-5"
                    >
                        <label
                            htmlFor="username"
                            className="text-sm font-medium"
                        >
                            Which channel?
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="username"
                                value={form.data.username}
                                onChange={(e) =>
                                    form.setData('username', e.target.value)
                                }
                                placeholder="@channel or t.me link"
                            />
                            <Button
                                type="submit"
                                variant="destructive"
                                className="h-10"
                                disabled={form.processing}
                            >
                                Get a code
                            </Button>
                        </div>
                        <InputError message={form.errors.username} />
                    </form>
                ) : (
                    <Button asChild size="lg" className="mt-10">
                        <Link
                            href={`/login?intended=${encodeURIComponent(`/remove${channel ? `?channel=${channel}` : ''}`)}`}
                        >
                            Log in with Telegram to continue
                            <ArrowRight />
                        </Link>
                    </Button>
                )}

                <p className="mt-8 text-sm text-pretty text-muted-foreground">
                    Not the owner, but something on a page is wrong or private?
                    Use the Report button on that page, or see the{' '}
                    <Link
                        href="/takedown-policy"
                        className="text-foreground underline underline-offset-4"
                    >
                        takedown policy
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}
