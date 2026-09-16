<?php

namespace App\Enums;

/**
 * Matches the Stitch report form's Level I-IV severity cards exactly
 * (stitch/.../electronic_incident_report_form_hopss_e_incident/code.html).
 */
enum Severity: string
{
    case Level1Low = 'level_1_low';
    case Level2Moderate = 'level_2_moderate';
    case Level3High = 'level_3_high';
    case Level4CriticalSentinel = 'level_4_critical_sentinel';

    public function label(): string
    {
        return match ($this) {
            self::Level1Low => 'Low Risk',
            self::Level2Moderate => 'Moderate Risk',
            self::Level3High => 'High Severity',
            self::Level4CriticalSentinel => 'Critical / Sentinel',
        };
    }

    public function romanNumeral(): string
    {
        return match ($this) {
            self::Level1Low => 'Level I',
            self::Level2Moderate => 'Level II',
            self::Level3High => 'Level III',
            self::Level4CriticalSentinel => 'Level IV',
        };
    }

    public function isSentinel(): bool
    {
        return $this === self::Level4CriticalSentinel;
    }
}
