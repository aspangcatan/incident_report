<?php

namespace App\Enums;

/**
 * Root cause analysis tool a finding belongs to. Every investigation can use any
 * of them (none is required); "simple" is a plain finding.
 *
 * Column use per tool (investigation_findings):
 *   five_whys    question = "Why…?", finding = "Because…", sequence
 *   fishbone     group_name = FishboneCategory, finding = the cause
 *   process_map  question = what should happen, finding = what actually happened, sequence, is_flagged = deviation
 *   timeline     occurred_at, finding = what happened, is_flagged = went wrong here
 *   barrier      question = the barrier/defense, group_name = BarrierStatus, finding = why
 */
enum RcaTool: string
{
    case Simple = 'simple';
    case FiveWhys = 'five_whys';
    case Fishbone = 'fishbone';
    case ProcessMap = 'process_map';
    case Timeline = 'timeline';
    case Barrier = 'barrier';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Findings',
            self::FiveWhys => '5 Whys',
            self::Fishbone => 'Fishbone',
            self::ProcessMap => 'Process Mapping',
            self::Timeline => 'Timeline Analysis',
            self::Barrier => 'Barrier/Defense Analysis',
        };
    }

    /** Tools whose rows are numbered in the order they were added. */
    public function isSequenced(): bool
    {
        return in_array($this, [self::FiveWhys, self::ProcessMap], true);
    }
}
