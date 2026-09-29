<?php

namespace App\Enums;

/** The six "bones" of the Fishbone (Ishikawa) diagram. */
enum FishboneCategory: string
{
    case People = 'people';
    case Methods = 'methods';
    case Equipment = 'equipment';
    case Environment = 'environment';
    case Materials = 'materials';
    case Management = 'management';

    public function label(): string
    {
        return match ($this) {
            self::People => 'People',
            self::Methods => 'Methods/Procedures',
            self::Equipment => 'Equipment',
            self::Environment => 'Environment',
            self::Materials => 'Materials/Medication',
            self::Management => 'Management/Communication',
        };
    }
}
