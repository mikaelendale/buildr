<?php

namespace App\Enums;

enum CreatorStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Published => 'Published',
            self::ChangesRequested => 'Changes requested',
            self::Rejected => 'Rejected',
            self::Hidden => 'Hidden',
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
