<?php

namespace App\Enums;

enum AlertUrgency: string
{
    case Information = 'information';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Information => 'Information',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }
}
