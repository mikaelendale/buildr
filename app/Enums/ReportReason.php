<?php

namespace App\Enums;

enum ReportReason: string
{
    case Impersonation = 'impersonation';
    case WrongInfo = 'wrong_info';
    case Privacy = 'privacy';
    case Offensive = 'offensive';
    case Spam = 'spam';
    case RemoveMe = 'remove_me';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Impersonation => 'Impersonation',
            self::WrongInfo => 'Wrong information',
            self::Privacy => 'Privacy',
            self::Offensive => 'Offensive',
            self::Spam => 'Spam',
            self::RemoveMe => 'Remove me',
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
