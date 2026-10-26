<?php

namespace App\Enums;

enum ContentSource: string
{
    case Ai = 'ai';
    case Creator = 'creator';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'Ai',
            self::Creator => 'Creator',
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
