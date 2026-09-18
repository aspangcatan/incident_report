<?php

namespace App\Enums;

enum CorrectiveActionType: string
{
    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Corrective => 'Corrective',
            self::Preventive => 'Preventive',
            self::Both => 'Corrective & Preventive',
        };
    }
}
