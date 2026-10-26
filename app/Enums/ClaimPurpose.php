<?php

namespace App\Enums;

enum ClaimPurpose: string
{
    case Claim = 'claim';
    case Removal = 'removal';
    case Dispute = 'dispute';
    case Rename = 'rename';

    public function label(): string
    {
        return match ($this) {
            self::Claim => 'Claim',
            self::Removal => 'Removal',
            self::Dispute => 'Dispute',
            self::Rename => 'Rename',
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
