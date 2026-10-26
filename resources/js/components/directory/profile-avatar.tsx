import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Round avatar with a white ring, so it sits on cards and on the canvas
 * alike. Missing photos fall back to mint initials.
 */
export default function ProfileAvatar({
    name,
    src,
    className,
}: {
    name: string;
    src: string | null | undefined;
    className?: string;
}) {
    return (
        <Avatar
            className={cn(
                'size-11 rounded-full shadow-[0_1px_2px_oklch(0_0_0/0.08)] ring-2 ring-card',
                className,
            )}
        >
            {src && <AvatarImage src={src} alt="" className="object-cover" />}
            <AvatarFallback className="rounded-full bg-brand-soft font-medium text-brand-ink">
                {initials(name)}
            </AvatarFallback>
        </Avatar>
    );
}
