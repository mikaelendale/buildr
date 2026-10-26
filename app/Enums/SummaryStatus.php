<?php

namespace App\Enums;

enum SummaryStatus: string
{
    case Generating = 'generating';
    case Draft = 'draft';
    case Verified = 'verified';
    case FailedVerification = 'failed_verification';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Generating => 'Generating',
            self::Draft => 'Draft',
            self::Verified => 'Verified',
            self::FailedVerification => 'Failed verification',
            self::Approved => 'Approved',
            self::Superseded => 'Superseded',
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
