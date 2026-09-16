<?php

namespace App\Enums;

/**
 * Primary system role. "Reporter" is deliberately not a role here — every
 * authenticated user can file an incident report; this enum only governs
 * review/investigation/admin permissions. See docs/architecture.md §2.6 & §4.
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

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Supervisor => 'Supervisor',
            self::Investigator => 'Investigator',
            self::DepartmentHead => 'Department Head',
            self::QualitySafetyOfficer => 'Quality & Safety Officer',
            self::Administrator => 'Administrator',
            self::Management => 'Management',
        };
    }
}
