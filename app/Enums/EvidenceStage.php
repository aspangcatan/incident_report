<?php

namespace App\Enums;

/** When a file was added to an incident's evidence. */
enum EvidenceStage: string
{
    case Report = 'report';
    case Assessment = 'assessment';
    case Investigation = 'investigation';
    case Capa = 'capa';

    public function label(): string
    {
        return match ($this) {
            self::Report => 'Report',
            self::Assessment => 'Department Assessment',
            self::Investigation => 'Investigation',
            self::Capa => 'Corrective Action proof',
        };
    }
}
