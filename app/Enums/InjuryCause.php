<?php

namespace App\Enums;

/** How an injury happened (accident report). "Others (Specify)" is the free-text incidents.injury_cause_other. */
enum InjuryCause: string
{
    case CaughtBetween = 'caught_between';
    case StruckBy = 'struck_by';
    case StruckAgainst = 'struck_against';
    case SlipsTripsFalls = 'slips_trips_falls';
    case SharpObject = 'sharp_object';
    case ExtremeTemperature = 'extreme_temperature';
    case ImproperHandling = 'improper_handling';
    case EquipmentFailure = 'equipment_failure';
    case ElectricCurrent = 'electric_current';
    case InhalationAbsorptionIngestion = 'inhalation_absorption_ingestion';
    case ChemicalBiologicalSplash = 'chemical_biological_splash';

    public function label(): string
    {
        return match ($this) {
            self::CaughtBetween => 'Caught Between',
            self::StruckBy => 'Struck By',
            self::StruckAgainst => 'Struck Against',
            self::SlipsTripsFalls => 'Slips/Trips/Falls',
            self::SharpObject => 'Sharp Object',
            self::ExtremeTemperature => 'Extreme Temperature',
            self::ImproperHandling => 'Improper Handling',
            self::EquipmentFailure => 'Equipment Failure',
            self::ElectricCurrent => 'Contact w/ Electric Current',
            self::InhalationAbsorptionIngestion => 'Inhalation/Absorption/Ingestion',
            self::ChemicalBiologicalSplash => 'Chemical/Biological Splash',
        };
    }

    /** value => label, for the report forms. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
