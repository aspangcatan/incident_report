<?php

namespace App\Enums;

/**
 * Root-cause analysis method used for an investigation's findings.
 * usesSequence()/usesCategory() govern which finding fields apply for a
 * given methodology (see docs/architecture.md for Phase 5 details).
 */
enum InvestigationMethodology: string
{
    case FiveWhys = 'five_whys';
    case Fishbone = 'fishbone';
    case Hfacs = 'hfacs';
    case ContributingFactors = 'contributing_factors';

    public function label(): string
    {
        return match ($this) {
            self::FiveWhys => '5 Whys',
            self::Fishbone => 'Fishbone (Ishikawa)',
            self::Hfacs => 'Human Factors (HFACS)',
            self::ContributingFactors => 'Contributing Factors',
        };
    }

    public function usesSequence(): bool
    {
        return $this === self::FiveWhys;
    }

    public function usesCategory(): bool
    {
        return in_array($this, [self::Fishbone, self::Hfacs, self::ContributingFactors], true);
    }
}
