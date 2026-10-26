<?php

namespace App\Enums;

enum ChannelVisibility: string
{
    case Hidden = 'hidden';
    case Published = 'published';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Hidden',
            self::Published => 'Published',
            self::Removed => 'Removed',
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
