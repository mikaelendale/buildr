import { ArrowUpRight } from 'lucide-react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import { formatCount } from '@/lib/format';
import type { ChannelChip } from '@/types';

/**
 * Channels row (plan §9.4 #2): title, @username, subscribers, open in Telegram.
 */
export default function ChannelChips({
    channels,
}: {
    channels: ChannelChip[];
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            {channels.map((channel) => (
                <div
                    key={channel.username}
                    id={`channel-${channel.username}`}
                    className="flex min-w-0 items-center gap-3 rounded-full bg-card py-1.5 pr-1.5 pl-1.5 shadow-[0_1px_2px_oklch(0_0_0/0.05)] ring-1 ring-border/80 sm:max-w-sm"
                >
                    <ProfileAvatar
                        name={channel.title}
                        src={channel.avatar}
                        className="size-8 shadow-none ring-0"
                    />
                    <div className="min-w-0 flex-1 leading-tight">
                        <div className="truncate text-sm font-medium">
                            {channel.title}
                        </div>
                        <div className="flex items-center gap-1.5 font-mono text-[11px] text-muted-foreground">
                            <span className="truncate">
                                @{channel.username}
                            </span>
                            {channel.subscribers !== null && (
                                <span className="tabular shrink-0">
                                    · {formatCount(channel.subscribers)}
                                </span>
                            )}
                        </div>
                    </div>
                    {channel.telegram_url && (
                        <a
                            href={channel.telegram_url}
                            target="_blank"
                            rel="noopener"
                            aria-label={`Open @${channel.username} in Telegram`}
                            className="flex h-8 shrink-0 items-center gap-1 rounded-full bg-secondary px-3 text-xs font-medium transition-colors hover:bg-accent hover:text-foreground"
                        >
                            Open
                            <ArrowUpRight className="size-3.5" />
                        </a>
                    )}
                </div>
            ))}
        </div>
    );
}
