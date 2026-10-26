<?php

namespace App\Enums;

enum PostType: string
{
    case ProjectLaunch = 'project_launch';
    case ProgressUpdate = 'progress_update';
    case Opinion = 'opinion';
    case Tutorial = 'tutorial';
    case ResourceShare = 'resource_share';
    case Life = 'life';
    case Promo = 'promo';
    case Announcement = 'announcement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProjectLaunch => 'Project launch',
            self::ProgressUpdate => 'Progress update',
            self::Opinion => 'Opinion',
            self::Tutorial => 'Tutorial',
            self::ResourceShare => 'Resource share',
            self::Life => 'Life',
            self::Promo => 'Promo',
            self::Announcement => 'Announcement',
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
