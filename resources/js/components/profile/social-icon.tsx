import {
    AtSign,
    Github,
    Globe,
    Instagram,
    Linkedin,
    Music2,
    Newspaper,
    Send,
    Twitter,
    Youtube,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const ICONS: Record<string, LucideIcon> = {
    x: Twitter,
    github: Github,
    linkedin: Linkedin,
    instagram: Instagram,
    tiktok: Music2,
    youtube: Youtube,
    website: Globe,
    substack: Newspaper,
    telegram: Send,
    other: AtSign,
};

export default function SocialIcon({
    platform,
    className,
}: {
    platform: string;
    className?: string;
}) {
    const Icon = ICONS[platform] ?? Globe;

    return <Icon className={className} />;
}
