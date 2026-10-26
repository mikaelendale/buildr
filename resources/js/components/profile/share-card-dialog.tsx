import { Check, Copy, Download, ImageIcon, Send } from 'lucide-react';
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
import { cn } from '@/lib/utils';

/**
 * The share card (same PNG as the og:image) as something to post: preview,
 * copy the image straight to the clipboard, download it, or share the link
 * to Telegram with a ready-made caption.
 */
export default function ShareCardDialog({
    type,
    id,
    profileUrl,
    name,
    fresh = false,
    own = false,
    trigger,
    className,
}: {
    type: 'creator' | 'channel';
    id: number;
    profileUrl: string;
    name: string;
    /** Owners re-render the card when opening it from the dashboard. */
    fresh?: boolean;
    /** Speaks to the page's owner ("your card", "your channel"). */
    own?: boolean;
    trigger?: React.ReactNode;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState<'image' | 'caption' | null>(null);
    const [loaded, setLoaded] = useState(false);
    const src = `/share-card/${type}/${id}.png${fresh ? '?fresh=1' : ''}`;
    const caption = `${name} on Buildr: ${profileUrl}`;

    const flash = (what: 'image' | 'caption') => {
        setCopied(what);
        window.setTimeout(() => setCopied(null), 2000);
    };

    const copyImage = async () => {
        try {
            // Safari wants the promise inside the ClipboardItem.
            const blob = fetch(src.replace('?fresh=1', '')).then((r) =>
                r.blob(),
            );
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

    const telegram = `https://t.me/share/url?url=${encodeURIComponent(profileUrl)}&text=${encodeURIComponent(`${name} on Buildr`)}`;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setLoaded(false);
            }}
        >
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button variant="outline" size="sm" className={className}>
                        <ImageIcon />
                        <span className="hidden sm:inline">Share card</span>
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
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
                    {open && (
                        <img
                            src={src}
                            alt={`${name}'s Buildr share card`}
                            onLoad={() => setLoaded(true)}
                            className={cn(
                                'absolute inset-0 size-full object-cover transition-opacity duration-300',
                                loaded ? 'opacity-100' : 'opacity-0',
                            )}
                        />
                    )}
                    {!loaded && (
                        <div className="absolute inset-0 animate-pulse bg-secondary" />
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button onClick={copyImage}>
                        {copied === 'image' ? <Check /> : <Copy />}
                        {copied === 'image' ? 'Copied' : 'Copy image'}
                    </Button>
                    <Button asChild variant="outline">
                        <a href={`/share-card/${type}/${id}.png?download=1`}>
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
