<?php

namespace App\Enums;

enum LinkKind: string
{
    case Github = 'github';
    case X = 'x';
    case Telegram = 'telegram';
    case Website = 'website';
    case Product = 'product';
    case Youtube = 'youtube';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::X => 'X',
            self::Telegram => 'Telegram',
            self::Website => 'Website',
            self::Product => 'Product',
            self::Youtube => 'YouTube',
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
