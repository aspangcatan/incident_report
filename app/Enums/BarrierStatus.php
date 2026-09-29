<?php

namespace App\Enums;

/** How a barrier/defense performed in the incident (Barrier/Defense Analysis). */
enum BarrierStatus: string
{
    case Worked = 'worked';
    case Failed = 'failed';
    case Missing = 'missing';
    case NotUsed = 'not_used';

    public function label(): string
    {
        return match ($this) {
            self::Worked => 'Worked',
            self::Failed => 'Failed',
            self::Missing => 'Missing',
            self::NotUsed => 'Not used',
        };
    }
}
