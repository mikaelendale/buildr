<?php

namespace App\Enums;

enum PostTone: string
{
    case Hype = 'hype';
    case Reflective = 'reflective';
    case Educational = 'educational';
    case Casual = 'casual';
    case Funny = 'funny';
    case Serious = 'serious';

    public function label(): string
    {
        return match ($this) {
            self::Hype => 'Hype',
            self::Reflective => 'Reflective',
            self::Educational => 'Educational',
            self::Casual => 'Casual',
            self::Funny => 'Funny',
            self::Serious => 'Serious',
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
