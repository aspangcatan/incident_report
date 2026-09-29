<?php

namespace App\Enums;

/** What caused an injury (accident report). "Others (Specify)" is the free-text incidents.injury_agent_other. */
enum InjuryAgent: string
{
    case MachineryEquipment = 'machinery_equipment';
    case MedicalDevice = 'medical_device';
    case NeedleSharp = 'needle_sharp';
    case BedWheelchairTrolley = 'bed_wheelchair_trolley';
    case FurnitureFixtures = 'furniture_fixtures';
    case FloorStairsWalkway = 'floor_stairs_walkway';
    case Vehicle = 'vehicle';
    case ChemicalsMedicines = 'chemicals_medicines';
    case BloodBodyFluids = 'blood_body_fluids';
    case Electricity = 'electricity';
    case HotSurfaceLiquid = 'hot_surface_liquid';
    case HandTools = 'hand_tools';
    case AnotherPerson = 'another_person';

    public function label(): string
    {
        return match ($this) {
            self::MachineryEquipment => 'Machinery or equipment',
            self::MedicalDevice => 'Medical device or instrument',
            self::NeedleSharp => 'Needle or sharp object',
            self::BedWheelchairTrolley => 'Bed, wheelchair, stretcher or trolley',
            self::FurnitureFixtures => 'Furniture or fixtures',
            self::FloorStairsWalkway => 'Floor, stairs or walkway surface',
            self::Vehicle => 'Vehicle',
            self::ChemicalsMedicines => 'Chemicals or medicines',
            self::BloodBodyFluids => 'Blood or body fluids',
            self::Electricity => 'Electricity',
            self::HotSurfaceLiquid => 'Hot surface or hot liquid',
            self::HandTools => 'Hand tools',
            self::AnotherPerson => 'Another person / patient',
        };
    }

    /** value => label, for the report forms. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
