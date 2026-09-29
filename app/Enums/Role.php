<?php

namespace App\Enums;

/**
 * Primary system role, read from tdh_user.user_priv.level. "Reporter" is not a
 * role — every user can report (Staff = no IR row). Labels follow the client's
 * role names; see docs/superpowers/specs/2026-09-29-client-roles-and-triage-design.md.
 */
enum Role: string
{
    case Staff = 'staff';
    case Supervisor = 'supervisor';
    case Investigator = 'investigator';
    case DepartmentHead = 'department_head';
    case QualitySafetyOfficer = 'quality_safety_officer';
    case Administrator = 'administrator';
    case Management = 'management';
    case Leadership = 'leadership';
    case CqiCommittee = 'cqi_committee';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Supervisor => 'Department Safety Focal Person',
            self::Investigator => 'Investigator',
            self::DepartmentHead => 'Department/Service Head',
            self::QualitySafetyOfficer => 'Patient Safety/CQI Office',
            self::Administrator => 'IT/System Administrator',
            self::Management => 'Hospital Executive',
            self::Leadership => 'Medical/Nursing/Ancillary Leadership',
            self::CqiCommittee => 'Patient Safety/CQI Committee',
        };
    }

    /** Sees every incident hospital-wide. */
    public function seesAllIncidents(): bool
    {
        return in_array($this, [self::QualitySafetyOfficer, self::Management, self::CqiCommittee], true);
    }
}
