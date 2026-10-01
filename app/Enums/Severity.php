<?php

namespace App\Enums;

/**
 * The client's five-level Risk Triage scale (2026-10-01). meaning() is the
 * client's "Indicative Meaning" and is shown wherever a level is chosen.
 */
enum Severity: string
{
    case Level1Low = 'level_1_low';
    case Level2Moderate = 'level_2_moderate';
    case Level3High = 'level_3_high';
    case Level4Critical = 'level_4_critical';
    case Level5Sentinel = 'level_5_sentinel';

    public function label(): string
    {
        return match ($this) {
            self::Level1Low => 'Low',
            self::Level2Moderate => 'Moderate',
            self::Level3High => 'High',
            self::Level4Critical => 'Critical',
            self::Level5Sentinel => 'Sentinel',
        };
    }

    public function romanNumeral(): string
    {
        return match ($this) {
            self::Level1Low => 'Level I',
            self::Level2Moderate => 'Level II',
            self::Level3High => 'Level III',
            self::Level4Critical => 'Level IV',
            self::Level5Sentinel => 'Level V',
        };
    }

    public function meaning(): string
    {
        return match ($this) {
            self::Level1Low => 'Near miss / no harm or low-risk event',
            self::Level2Moderate => 'Temporary harm or intervention required',
            self::Level3High => 'Significant harm, prolonged hospitalization or high-risk event',
            self::Level4Critical => 'Permanent or life-threatening harm',
            self::Level5Sentinel => 'Death or serious permanent harm / other agency-defined sentinel event',
        };
    }

    public function isSentinel(): bool
    {
        return $this === self::Level5Sentinel;
    }

    /** High, Critical and Sentinel: formal investigation required, CQI Committee closure sign-off. */
    public function isHighOrAbove(): bool
    {
        return in_array($this, [self::Level3High, self::Level4Critical, self::Level5Sentinel], true);
    }
}
