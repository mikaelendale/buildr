<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Idea = 'idea';
    case Building = 'building';
    case Launched = 'launched';
    case Abandoned = 'abandoned';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Building => 'Building',
            self::Launched => 'Launched',
            self::Abandoned => 'Abandoned',
            self::Unknown => 'Unknown',
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
