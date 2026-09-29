<?php

namespace App\Enums;

/** How an injury happened (accident report). "Others (Specify)" is the free-text incidents.injury_cause_other. */
enum InjuryCause: string
{
    case SlipTripFall = 'slip_trip_fall';
    case FallFromHeight = 'fall_from_height';
    case StruckBy = 'struck_by';
    case StruckAgainst = 'struck_against';
    case CaughtInBetween = 'caught_in_between';
    case Needlestick = 'needlestick';
    case CutLaceration = 'cut_laceration';
    case BurnScald = 'burn_scald';
    case ChemicalExposure = 'chemical_exposure';
    case BodyFluidExposure = 'body_fluid_exposure';
    case ElectricShock = 'electric_shock';
    case Overexertion = 'overexertion';
    case AssaultViolence = 'assault_violence';
    case AnimalInsectBite = 'animal_insect_bite';

    public function label(): string
    {
        return match ($this) {
            self::SlipTripFall => 'Slip, trip or fall (same level)',
            self::FallFromHeight => 'Fall from height / stairs',
            self::StruckBy => 'Struck by an object',
            self::StruckAgainst => 'Struck against an object',
            self::CaughtInBetween => 'Caught in or between objects',
            self::Needlestick => 'Needlestick / sharps injury',
            self::CutLaceration => 'Cut or laceration',
            self::BurnScald => 'Burn or scald',
            self::ChemicalExposure => 'Exposure to chemicals / hazardous substances',
            self::BodyFluidExposure => 'Exposure to blood or body fluids',
            self::ElectricShock => 'Electric shock',
            self::Overexertion => 'Lifting / overexertion / manual handling',
            self::AssaultViolence => 'Assault or violence',
            self::AnimalInsectBite => 'Animal or insect bite',
        };
    }

    /** value => label, for the report forms. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
