<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Approved => 'Approved',
            self::Returned => 'Returned for Revision',
        };
    }
}
