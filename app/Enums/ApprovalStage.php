<?php

namespace App\Enums;

/**
 * Who decides an approvals row. Every closure goes to the CQI Office first;
 * High/Sentinel incidents then need a second, Committee row before closing.
 */
enum ApprovalStage: string
{
    case CqiOffice = 'cqi_office';
    case Committee = 'committee';

    public function label(): string
    {
        return match ($this) {
            self::CqiOffice => 'Patient Safety/CQI Office',
            self::Committee => 'Patient Safety/CQI Committee',
        };
    }

    /** The only role that may decide a row at this stage. */
    public function deciderRole(): Role
    {
        return match ($this) {
            self::CqiOffice => Role::QualitySafetyOfficer,
            self::Committee => Role::CqiCommittee,
        };
    }
}
