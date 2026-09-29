<?php

namespace App\Enums;

/**
 * What kind of cause a root-cause finding is. Stored in
 * investigation_findings.category and counted on the Analytics
 * "Root cause distribution" chart.
 */
enum RootCauseType: string
{
    case Staffing = 'staffing';
    case Procedure = 'procedure';
    case Equipment = 'equipment';
    case Environment = 'environment';
    case Communication = 'communication';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Staffing => 'Staffing',
            self::Procedure => 'Procedure',
            self::Equipment => 'Equipment',
            self::Environment => 'Environment',
            self::Communication => 'Communication',
            self::Other => 'Other',
        };
    }
}
