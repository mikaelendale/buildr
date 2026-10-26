<?php

namespace App\Enums;

enum PostLang: string
{
    case En = 'en';
    case Am = 'am';
    case Mixed = 'mixed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::En => 'English',
            self::Am => 'Amharic',
            self::Mixed => 'Mixed',
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
