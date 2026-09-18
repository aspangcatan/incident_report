<?php

namespace App\Enums;

/**
 * Investigation lifecycle stage. An investigations row only exists once
 * start() runs and is created directly as InProgress — NotStarted is never
 * written by current code; it's reserved for a future "re-opened incident
 * gets a new investigation history row" case.
 */
enum InvestigationStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Started',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
        };
    }
}
