import { BadgeCheck, MapPin } from 'lucide-react';
import ProfileAvatar from '@/components/directory/profile-avatar';
import { Badge } from '@/components/ui/badge';

const TAG_TONES = ['sky', 'violet', 'amber'] as const;

/**
 * Profile identity block: a large ringed avatar, the name in a light weight,
 * a row of pastel pills, then the AI-written hook.
 */
export default function ProfileHeader({
    name,
    avatar,
    verified,
    subtitle,
    hook,
    vibeTags,
    location,
    children,
}: {
    name: string;
    avatar: string | null;
    verified?: boolean;
    subtitle?: React.ReactNode;
    hook: string | null;
    vibeTags: string[];
    location?: string | null;
    children?: React.ReactNode;
}) {
    return (
        <header className="flex flex-col gap-6 sm:flex-row sm:items-center">
            <ProfileAvatar
                name={name}
                src={avatar}
                className="size-24 text-2xl shadow-soft ring-4 sm:size-28"
            />
            <div className="min-w-0 flex-1">
                <h1 className="flex items-center gap-2 text-[30px] leading-tight font-normal tracking-[-0.03em] sm:text-[34px]">
                    <span className="min-w-0 break-words">{name}</span>
                    {verified && (
                        <BadgeCheck
                            className="size-6 shrink-0 text-brand-ink"
                            aria-label="Verified owner"
                        />
                    )}
                </h1>

                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    {verified && <Badge variant="mint">Verified owner</Badge>}
                    {vibeTags.map((tag, i) => (
                        <Badge
                            key={tag}
                            variant={TAG_TONES[i % TAG_TONES.length]}
                        >
                            {tag}
                        </Badge>
                    ))}
                    {subtitle && (
                        <span className="ml-1 text-sm text-muted-foreground">
                            {subtitle}
                        </span>
                    )}
                    {location && (
                        <span className="ml-1 flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin className="size-3.5" />
                            {location}
                        </span>
                    )}
                </div>

                {hook && (
                    <p className="mt-4 max-w-2xl text-lg leading-snug font-light text-pretty text-foreground/85">
                        {hook}
                    </p>
                )}

                {children && <div className="mt-3">{children}</div>}
            </div>
        </header>
    );
}
