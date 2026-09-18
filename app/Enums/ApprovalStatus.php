<?php

namespace App\Enums;

/**
 * Status of a single closure-approval request. Unlike other lifecycle
 * enums in this app, `Returned` is terminal for its own row, not a dead
 * end for the incident: a returned incident goes back to
 * IncidentStatus::CorrectiveAction, and resubmitting for approval later
 * creates a brand-new `approvals` row rather than resetting this one back
 * to Pending - so an incident's approval history is a list of these rows,
 * not a single mutable record.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Approval',
            self::Approved => 'Approved',
            self::Returned => 'Returned for Revision',
        };
    }
}
