<?php

namespace App\Enums;

/**
 * Incident lifecycle stage, draft through closed. Not every incident visits
 * every stage — which stages a given severity requires lives in
 * config/incident_workflow.php (see docs/architecture.md §3.1, §5).
 */
enum IncidentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ForReview = 'for_review';
    case Reviewed = 'reviewed';
    case Assigned = 'assigned';
    case UnderInvestigation = 'under_investigation';
    case CorrectiveAction = 'corrective_action';
    case ForVerification = 'for_verification';
    case Verified = 'verified';
    case ForApproval = 'for_approval';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::ForReview => 'For Review',
            self::Reviewed => 'Reviewed',
            self::Assigned => 'Assigned',
            self::UnderInvestigation => 'Under Investigation',
            self::CorrectiveAction => 'Corrective Action',
            self::ForVerification => 'For Verification',
            self::Verified => 'Verified',
            self::ForApproval => 'For Approval',
            self::Closed => 'Closed',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
