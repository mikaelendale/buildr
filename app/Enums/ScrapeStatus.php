<?php

namespace App\Enums;

enum ScrapeStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Backfilling = 'backfilling';
    case Incremental = 'incremental';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Idle',
            self::Queued => 'Queued',
            self::Backfilling => 'Backfilling',
            self::Incremental => 'Incremental',
            self::Failed => 'Failed',
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
