<?php

namespace App\Enums;

/**
 * `Completed` is part of the approved schema (docs/architecture.md §2.4)
 * but is never written by this app's CorrectiveActionService — completing
 * a CAPA goes straight to ForVerification in one action (per the confirmed
 * 2026-09-18 design decision). Kept for schema fidelity and in case a
 * future workflow variant needs a distinct "done, not yet submitted" state.
 */
enum CorrectiveActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case ForVerification = 'for_verification';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::ForVerification => 'For Verification',
            self::Verified => 'Verified',
        };
    }
}
