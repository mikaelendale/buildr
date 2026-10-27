import { useForm, usePage } from '@inertiajs/react';
import { ArrowUpRight, Megaphone, Send } from 'lucide-react';
import { useState } from 'react';
import { Card, Counter, DataTable } from '@/components/admin/ui';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';

type Props = {
    counts: {
        reach: number;
        connected: number;
        muted: number;
        paused: number;
        creators: number;
    };
    footer: string;
    history: {
        id: number;
        body: string;
        button_label: string | null;
        button_url: string | null;
        recipients: number;
        by: string | null;
        created_at: string | null;
    }[];
};

const MAX = 3000;

/**
 * The DM as Telegram shows it: megaphone, the text, the mute line, and the
 * link button under the bubble.
 */
function Preview({
    body,
    label,
    footer,
    bot,
}: {
    body: string;
    label: string;
    footer: string;
    bot: string | null;
}) {
    return (
        <div className="rounded-[20px] bg-secondary p-4 ring-1 ring-border/60">
            <div className="mb-3 flex items-center justify-between text-xs text-muted-foreground">
                <span className="eyebrow">Preview</span>
                {bot && <span className="font-mono">@{bot}</span>}
            </div>
            <div className="max-w-sm space-y-1.5">
                <div className="rounded-2xl rounded-bl-md bg-card px-3.5 py-3 text-sm leading-relaxed whitespace-pre-line shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                    <span>📣 </span>
                    {body.trim() || (
                        <span className="text-ink-soft">
                            Your message shows up here.
                        </span>
                    )}
                    {'\n\n'}
                    <i className="text-muted-foreground">{footer}</i>
                </div>
                {label.trim() && (
                    <div className="flex items-center justify-center gap-1 rounded-xl bg-card px-3 py-2 text-sm font-medium shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/50">
                        {label.trim()}
                        <ArrowUpRight className="size-3.5 text-muted-foreground" />
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Announcements({ counts, footer, history }: Props) {
    const { telegramBot } = usePage().props;
    const [confirming, setConfirming] = useState(false);
    const form = useForm({
        body: '',
        button_label: '',
        button_url: '',
        to: '',
    });

    const empty = form.data.body.trim() === '';

    const send = () =>
        form.post('/admin/announcements', {
            preserveScroll: true,
            onSuccess: () => {
                setConfirming(false);
                form.reset('body', 'button_label', 'button_url');
            },
            onError: () => setConfirming(false),
        });

    const test = () =>
        form.post('/admin/announcements/test', { preserveScroll: true });

    return (
        <PageContainer
            title="Announcements"
            description="Tell creators about something new, on Telegram. It goes to everyone who connected the bot, except people who paused it or muted news."
            wide
        >
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Counter
                    label="Will get it"
                    value={counts.reach.toLocaleString('en')}
                />
                <Counter
                    label="Connected"
                    value={counts.connected.toLocaleString('en')}
                    hint={
                        <span className="tabular font-mono">
                            of {counts.creators.toLocaleString('en')}
                        </span>
                    }
                />
                <Counter
                    label="Muted news"
                    value={counts.muted.toLocaleString('en')}
                />
                <Counter
                    label="Paused bot"
                    value={counts.paused.toLocaleString('en')}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                <Card title="New announcement">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            setConfirming(true);
                        }}
                        className="space-y-5"
                    >
                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between gap-3">
                                <Label htmlFor="body">Message</Label>
                                <span className="tabular font-mono text-[11px] text-muted-foreground">
                                    {form.data.body.length}/{MAX}
                                </span>
                            </div>
                            <Textarea
                                id="body"
                                rows={7}
                                maxLength={MAX}
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                                placeholder="Share cards now come in light, dark and solid colors. Open yours and pick a look."
                            />
                            <p className="text-xs text-muted-foreground">
                                Plain text; line breaks are kept.
                            </p>
                            <InputError message={form.errors.body} />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
                            <div className="grid gap-2">
                                <Label htmlFor="button_label">
                                    Button{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="button_label"
                                    maxLength={40}
                                    value={form.data.button_label}
                                    onChange={(e) =>
                                        form.setData(
                                            'button_label',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Open my dashboard"
                                />
                                <InputError
                                    message={form.errors.button_label}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="button_url">Link</Label>
                                <Input
                                    id="button_url"
                                    type="url"
                                    value={form.data.button_url}
                                    onChange={(e) =>
                                        form.setData(
                                            'button_url',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="https://your-site.com/dashboard"
                                    className="font-mono text-xs"
                                />
                                <InputError message={form.errors.button_url} />
                            </div>
                        </div>

                        <div className="grid gap-2 rounded-xl bg-secondary/70 p-3">
                            <Label htmlFor="to" className="text-xs">
                                Send a test to one connected account first
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                <Input
                                    id="to"
                                    value={form.data.to}
                                    onChange={(e) =>
                                        form.setData('to', e.target.value)
                                    }
                                    placeholder="@telegram_username"
                                    className="min-w-40 flex-1 bg-card font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={test}
                                    disabled={
                                        form.processing ||
                                        empty ||
                                        form.data.to.trim() === ''
                                    }
                                >
                                    <Send />
                                    Send test
                                </Button>
                            </div>
                            <InputError message={form.errors.to} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    empty ||
                                    counts.reach === 0
                                }
                            >
                                <Megaphone />
                                Send to {counts.reach.toLocaleString('en')}{' '}
                                {counts.reach === 1 ? 'creator' : 'creators'}
                            </Button>
                            {counts.reach === 0 && (
                                <span className="text-xs text-pretty text-muted-foreground">
                                    Nobody to send to yet: creators are asked to
                                    connect Telegram when they log in.
                                </span>
                            )}
                        </div>
                    </form>
                </Card>

                <div className="lg:sticky lg:top-4 lg:self-start">
                    <Preview
                        body={form.data.body}
                        label={form.data.button_label}
                        footer={footer}
                        bot={telegramBot}
                    />
                </div>
            </div>

            <section className="space-y-2">
                <h2 className="eyebrow">Sent</h2>
                <DataTable
                    columns={[
                        { label: 'When', className: 'w-36' },
                        'Message',
                        { label: 'Reached', className: 'w-24 text-right' },
                    ]}
                    empty="Nothing sent yet."
                    emptyGlyph="send"
                >
                    {history.map((a) => (
                        <tr key={a.id}>
                            <td className="tabular align-top font-mono text-xs whitespace-nowrap text-muted-foreground">
                                {formatDate(a.created_at)}
                                <span className="block font-sans">
                                    {a.by ?? 'command line'}
                                </span>
                            </td>
                            <td className="align-top">
                                <p className="line-clamp-3 whitespace-pre-line text-foreground/85">
                                    {a.body}
                                </p>
                                {a.button_label && a.button_url && (
                                    <a
                                        href={a.button_url}
                                        target="_blank"
                                        rel="noopener"
                                        className="mt-1 inline-flex items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground hover:underline"
                                    >
                                        {a.button_label}
                                        <ArrowUpRight className="size-3" />
                                    </a>
                                )}
                            </td>
                            <td className="tabular text-right align-top font-mono text-xs">
                                {a.recipients.toLocaleString('en')}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            </section>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Send to {counts.reach.toLocaleString('en')}{' '}
                            {counts.reach === 1 ? 'creator' : 'creators'}?
                        </DialogTitle>
                        <DialogDescription>
                            It goes out on Telegram right away and can&apos;t be
                            taken back.
                        </DialogDescription>
                    </DialogHeader>
                    <Preview
                        body={form.data.body}
                        label={form.data.button_label}
                        footer={footer}
                        bot={telegramBot}
                    />
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button onClick={send} disabled={form.processing}>
                            {form.processing ? <Spinner /> : <Megaphone />}
                            Send now
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PageContainer>
    );
}

Announcements.layout = {
    breadcrumbs: [{ title: 'Announcements', href: '/admin/announcements' }],
};
