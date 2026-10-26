<?php

namespace App\Enums;

enum TelegramPageType: string
{
    case Channel = 'channel';
    case User = 'user';
    case Group = 'group';
    case Bot = 'bot';
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Channel => 'Channel',
            self::User => 'User',
            self::Group => 'Group',
            self::Bot => 'Bot',
            self::NotFound => 'Not found',
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
