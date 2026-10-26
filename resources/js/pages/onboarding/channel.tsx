import { router, useForm } from '@inertiajs/react';
import { ArrowRight, Loader2 } from 'lucide-react';
import OnboardingHeader from '@/components/creator/onboarding-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function ChannelStep({
    step,
    steps,
    channels,
}: {
    step: number;
    steps: string[];
    channels: string[];
}) {
    const form = useForm({ username: '' });
    const claimedByOther = form.errors.username?.includes('dispute') ?? false;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/onboarding/channel');
    };

    return (
        <>
            <OnboardingHeader
                step={step}
                steps={steps}
                title="Which channel is yours?"
                description="Paste your channel's @handle or t.me link. It needs to be a public channel."
            />

            {channels.length > 0 && (
                <div className="mb-5 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span>Already added</span>
                    {channels.map((c) => (
                        <span
                            key={c}
                            className="rounded-full bg-card px-2.5 py-0.5 font-mono text-xs text-foreground/80 ring-1 ring-border/80"
                        >
                            @{c}
                        </span>
                    ))}
                    <button
                        type="button"
                        className="ml-1 text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                        onClick={() => router.visit('/onboarding/basics')}
                    >
                        Skip to basics
                    </button>
                </div>
            )}

            <form onSubmit={submit} className="surface space-y-5 p-5 sm:p-6">
                <div className="space-y-2">
                    <Label htmlFor="username">Channel</Label>
                    <Input
                        id="username"
                        value={form.data.username}
                        onChange={(e) =>
                            form.setData('username', e.target.value)
                        }
                        placeholder="@yourchannel or https://t.me/yourchannel"
                        className="h-11 font-mono text-sm"
                        autoFocus
                        autoComplete="off"
                    />
                    <InputError message={form.errors.username} />
                    {claimedByOther && (
                        <Button
                            type="button"
                            variant="link"
                            className="px-0"
                            onClick={() =>
                                router.post('/claims/dispute', {
                                    username: form.data.username,
                                })
                            }
                        >
                            It&apos;s mine, open a dispute
                        </Button>
                    )}
                </div>
                <Button
                    size="lg"
                    type="submit"
                    disabled={
                        form.processing || form.data.username.trim() === ''
                    }
                >
                    {form.processing && <Loader2 className="animate-spin" />}
                    {form.processing ? 'Checking Telegram…' : 'Continue'}
                    {!form.processing && <ArrowRight />}
                </Button>
            </form>
        </>
    );
}
