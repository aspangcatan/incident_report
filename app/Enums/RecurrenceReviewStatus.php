<?php

namespace App\Enums;

enum RecurrenceReviewStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Waiting for the department',
            self::Submitted => 'Waiting for the CQI Office',
            self::Closed => 'Addressed',
        };
    }
}
