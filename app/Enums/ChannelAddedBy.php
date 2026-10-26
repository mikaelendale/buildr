<?php

namespace App\Enums;

enum ChannelAddedBy: string
{
    case Self = 'self';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'Self',
            self::Admin => 'Admin',
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
