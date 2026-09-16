<?php

namespace App\Enums;

enum PersonType: string
{
    case Patient = 'patient';
    case Staff = 'staff';
    case Visitor = 'visitor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'Patient',
            self::Staff => 'Staff',
            self::Visitor => 'Visitor',
            self::Other => 'Other',
        };
    }
}
