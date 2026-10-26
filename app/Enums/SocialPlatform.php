<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case X = 'x';
    case Github = 'github';
    case Linkedin = 'linkedin';
    case Instagram = 'instagram';
    case Tiktok = 'tiktok';
    case Youtube = 'youtube';
    case Website = 'website';
    case Substack = 'substack';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::X => 'X',
            self::Github => 'GitHub',
            self::Linkedin => 'LinkedIn',
            self::Instagram => 'Instagram',
            self::Tiktok => 'TikTok',
            self::Youtube => 'YouTube',
            self::Website => 'Website',
            self::Substack => 'Substack',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
