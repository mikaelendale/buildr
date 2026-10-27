import { router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Download,
    ImageIcon,
    Moon,
    Palette,
    Send,
    Sun,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import Segmented from '@/components/ui/segmented';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { CardColor, CardLook, CardTheme } from '@/types';

const THEMES = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'color', label: 'Color', icon: Palette },
];

/** Swatches show the solid card each color prints (same as the renderer). */
const COLORS: { value: CardColor; label: string; hex: string }[] = [
    { value: 'mint', label: 'Mint', hex: '#13785e' },
    { value: 'sky', label: 'Sky', hex: '#2a5ec2' },
    { value: 'violet', label: 'Violet', hex: '#5a3fcb' },
    { value: 'amber', label: 'Amber', hex: '#95580b' },
    { value: 'rose', label: 'Rose', hex: '#ad2a54' },
    { value: 'ink', label: 'Ink', hex: '#1c1c1a' },
];

/**
 * The card's PNG in a given look. Theme and color are always in the URL so
 * a changed look is never served from the browser cache.
 */
export function shareCardSrc(
    type: 'creator' | 'channel',
    id: number,
    look: CardLook,
    extra: Record<string, string> = {},
): string {
    return `/share-card/${type}/${id}.png?${new URLSearchParams({ ...look, ...extra })}`;
}

/**
 * The share card (same PNG as the og:image) as something to post: pick a
 * light, dark or solid-color card in any of six colors, preview it, copy
 * the image straight to the clipboard, download it, or share the link to
 * Telegram with a ready-made caption. Owners can keep a look as their link
 * preview (it also tones their page cover).
 */
export default function ShareCardDialog({
    type,
    id,
    profileUrl,
    name,
    card,
    fresh = false,
    own = false,
    trigger,
    className,
}: {
    type: 'creator' | 'channel';
    id: number;
    profileUrl: string;
    name: string;
    /** The owner's saved look: what the dialog opens on. */
    card: CardLook;
    /** Owners re-render the card when opening it from the dashboard. */
    fresh?: boolean;
    /** Speaks to the page's owner ("your card", "your channel"). */
    own?: boolean;
    trigger?: React.ReactNode;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [look, setLook] = useState<CardLook>(card);
    const [copied, setCopied] = useState<'image' | 'caption' | null>(null);
    const [shown, setShown] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [kept, setKept] = useState(false);

    const saved = look.theme === card.theme && look.color === card.color;
    const src = shareCardSrc(type, id, look);
    // Re-render once per open, only for the owner's own look (other looks
    // follow it). Keeping a look renders it on the server already.
    const imgSrc = fresh && saved && !kept ? `${src}&fresh=1` : src;
    const caption = `${name} on Buildr: ${profileUrl}`;
    const loading = shown !== imgSrc;

    const flash = (what: 'image' | 'caption') => {
        setCopied(what);
        window.setTimeout(() => setCopied(null), 2000);
    };

    const copyImage = async () => {
        try {
            // Safari wants the promise inside the ClipboardItem.
            const blob = fetch(src).then((r) => r.blob());
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': blob }),
            ]);
            flash('image');
        } catch {
            toast('Copying images isn’t supported here', {
                description: 'Download the card instead and post it.',
            });
        }
    };

    const copyCaption = async () => {
        try {
            await navigator.clipboard.writeText(caption);
            flash('caption');
        } catch {
            window.prompt('Copy this caption', caption);
        }
    };

    const keep = () => {
        router.put(`/dashboard/share-card/${type}/${id}`, look, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onSuccess: () => setKept(true),
            onFinish: () => setSaving(false),
        });
    };

    const telegram = `https://t.me/share/url?url=${encodeURIComponent(profileUrl)}&text=${encodeURIComponent(`${name} on Buildr`)}`;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setShown(null);
                setLook(card);
                setKept(false);
            }}
        >
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button
                        variant="outline"
                        size="sm"
                        className={className}
                        aria-label="Share card"
                    >
                        <ImageIcon />
                        <span className="hidden sm:inline">Share card</span>
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[calc(100dvh-2rem)] grid-cols-1 overflow-y-auto p-5 sm:max-w-2xl sm:p-6">
                <DialogHeader>
                    <DialogTitle className="text-xl font-light tracking-tight">
                        {own ? 'Your share card' : 'Share card'}
                    </DialogTitle>
                    <DialogDescription>
                        {own
                            ? 'Paste it into your channel or anywhere you post. It links back to your Buildr page.'
                            : 'Copy the card and post it anywhere. It links back to this page.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="relative overflow-hidden rounded-xl bg-secondary ring-1 ring-border/70">
                    <div className="aspect-[1200/630] w-full" />
                    {/* The last card stays up while the next look renders. */}
                    {shown && loading && (
                        <img
                            src={shown}
                            alt=""
                            className="absolute inset-0 size-full object-cover"
                        />
                    )}
                    {open && (
                        <img
                            key={imgSrc}
                            src={imgSrc}
                            alt={`${name}'s Buildr share card`}
                            onLoad={() => setShown(imgSrc)}
                            className={cn(
                                'absolute inset-0 size-full object-cover transition-opacity duration-300',
                                loading ? 'opacity-0' : 'opacity-100',
                            )}
                        />
                    )}
                    {!shown && (
                        <div className="absolute inset-0 animate-pulse bg-secondary" />
                    )}
                    {shown && loading && (
                        <span className="absolute top-3 right-3 flex size-7 items-center justify-center rounded-full bg-card/90 shadow-[0_1px_2px_oklch(0_0_0/0.08)] ring-1 ring-border/70 backdrop-blur-sm">
                            <Spinner className="size-3.5 text-muted-foreground" />
                        </span>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Segmented
                        label="Card"
                        items={THEMES}
                        value={look.theme}
                        onChange={(theme) =>
                            setLook({ ...look, theme: theme as CardTheme })
                        }
                    />
                    <div
                        role="radiogroup"
                        aria-label="Color"
                        className="flex items-center gap-1.5"
                    >
                        {COLORS.map((c) => {
                            const on = c.value === look.color;

                            return (
                                <button
                                    key={c.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={on}
                                    aria-label={c.label}
                                    title={c.label}
                                    onClick={() =>
                                        setLook({ ...look, color: c.value })
                                    }
                                    className={cn(
                                        'flex size-7 items-center justify-center rounded-full ring-1 ring-border/80 transition-[box-shadow,transform] duration-150 outline-none hover:scale-110 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                        on &&
                                            'shadow-[0_0_0_2px_var(--background),0_0_0_3.5px_var(--foreground)]',
                                    )}
                                    style={{ backgroundColor: c.hex }}
                                >
                                    {on && (
                                        <Check
                                            className="size-3.5 text-white"
                                            strokeWidth={3}
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {own && (
                    <div className="flex min-h-9 flex-wrap items-center justify-between gap-x-3 gap-y-2 rounded-xl bg-secondary px-3.5 py-2 text-sm">
                        <span className="text-muted-foreground">
                            {saved
                                ? 'This is your link preview and sets your page color.'
                                : 'Link previews still show your saved card.'}
                        </span>
                        {saved ? (
                            <span className="flex h-8 items-center gap-1 text-xs text-foreground/70">
                                <Check className="size-3.5" />
                                Saved
                            </span>
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={keep}
                                disabled={saving}
                            >
                                {saving && <Spinner />}
                                Use this look
                            </Button>
                        )}
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button onClick={copyImage}>
                        {copied === 'image' ? <Check /> : <Copy />}
                        {copied === 'image' ? 'Copied' : 'Copy image'}
                    </Button>
                    <Button asChild variant="outline">
                        <a
                            href={shareCardSrc(type, id, look, {
                                download: '1',
                            })}
                        >
                            <Download />
                            Download
                        </a>
                    </Button>
                    <Button asChild variant="ghost">
                        <a
                            href={telegram}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Send />
                            Telegram
                        </a>
                    </Button>
                </div>

                <button
                    type="button"
                    onClick={copyCaption}
                    className="group flex w-full items-center justify-between gap-3 rounded-xl bg-secondary px-3.5 py-2.5 text-left text-sm transition-colors hover:bg-accent"
                >
                    <span className="min-w-0 truncate font-mono text-xs text-muted-foreground">
                        {caption}
                    </span>
                    <span className="flex shrink-0 items-center gap-1 text-xs text-foreground/70">
                        {copied === 'caption' ? (
                            <Check className="size-3.5" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                        {copied === 'caption' ? 'Copied' : 'Copy caption'}
                    </span>
                </button>
            </DialogContent>
        </Dialog>
    );
}
