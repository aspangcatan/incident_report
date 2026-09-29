<?php

namespace App\Enums;

/**
 * What caused an injury (accident report). "Others (Specify)" is the free-text
 * incidents.injury_agent_other; Chemicals also needs incidents.injury_chemical_details.
 */
enum InjuryAgent: string
{
    case Needles = 'needles';
    case HandTool = 'hand_tool';
    case Machine = 'machine';
    case Radiation = 'radiation';
    case Chemicals = 'chemicals';
    case Stairs = 'stairs';
    case ElectricApparatus = 'electric_apparatus';
    case Surfaces = 'surfaces';
    case Ladders = 'ladders';
    case Vehicle = 'vehicle';

    public function label(): string
    {
        return match ($this) {
            self::Needles => 'Needles',
            self::HandTool => 'Hand Tool',
            self::Machine => 'Machine',
            self::Radiation => 'Radiation',
            self::Chemicals => 'Chemicals',
            self::Stairs => 'Stairs',
            self::ElectricApparatus => 'Electric Apparatus',
            self::Surfaces => 'Surfaces',
            self::Ladders => 'Ladders',
            self::Vehicle => 'Vehicle',
        };
    }

    /** value => label, for the report forms. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
