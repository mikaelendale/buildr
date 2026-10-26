<?php

namespace App\Enums;

enum ClaimStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
    case Failed = 'failed';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
            self::Disputed => 'Disputed',
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
