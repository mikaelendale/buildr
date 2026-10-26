import { Check, Link2, Send, Share2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Copy link, share to Telegram, share to X (plan §11).
 */
export default function ShareButton({
    url,
    text,
}: {
    url: string;
    text: string;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy this link', url);
        }
    };

    const telegram = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text)}`;
    const x = `https://x.com/intent/post?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text)}`;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm">
                    <Share2 />
                    Share
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={(e) => {
                        e.preventDefault();
                        void copy();
                    }}
                >
                    {copied ? <Check /> : <Link2 />}
                    {copied ? 'Copied' : 'Copy link'}
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a
                        href={telegram}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <Send />
                        Share on Telegram
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a href={x} target="_blank" rel="noopener noreferrer">
                        <Share2 />
                        Share on X
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
