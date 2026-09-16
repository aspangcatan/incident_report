<?php

namespace App\Enums;

/** Status of an immediate containment action recorded against an incident. */
enum ActionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
        };
    }
}
