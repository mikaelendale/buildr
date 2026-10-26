<?php

namespace App\Enums;

enum ChannelAvailability: string
{
    case Ok = 'ok';
    case NoPreview = 'no_preview';
    case NotFound = 'not_found';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Ok',
            self::NoPreview => 'No web preview',
            self::NotFound => 'Not found',
            self::Private => 'Private',
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
