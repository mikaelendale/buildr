<?php

namespace App\Enums;

enum ChannelClaimStatus: string
{
    case Unclaimed = 'unclaimed';
    case Claimed = 'claimed';

    public function label(): string
    {
        return match ($this) {
            self::Unclaimed => 'Unclaimed',
            self::Claimed => 'Claimed',
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
