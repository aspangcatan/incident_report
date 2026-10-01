import { findSeverity } from '@/Utils/severities';

const STATUS_LABELS = {
    draft: 'Draft',
    submitted: 'Submitted',
    for_review: 'For Review',
    reviewed: 'Reviewed',
    assigned: 'Assigned',
    under_investigation: 'Under Investigation',
    corrective_action: 'Corrective Action',
    for_verification: 'For Verification',
    verified: 'Verified',
    for_approval: 'For Approval',
    closed: 'Closed',
};

const STATUS_CLASSES = {
    draft: 'bg-surface-container text-on-surface-variant',
    submitted: 'bg-blue-100 text-blue-900',
    for_review: 'bg-amber-100 text-amber-900',
    reviewed: 'bg-blue-100 text-blue-900',
    assigned: 'bg-blue-100 text-blue-900',
    under_investigation: 'bg-primary-container text-on-primary',
    corrective_action: 'bg-amber-100 text-amber-900',
    for_verification: 'bg-secondary-container text-on-secondary-container',
    verified: 'bg-secondary-container text-on-secondary-container',
    for_approval: 'bg-amber-100 text-amber-900',
    closed: 'bg-emerald-100 text-emerald-900',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status;
}

export function statusBadgeClasses(status) {
    return STATUS_CLASSES[status] ?? 'bg-surface-container text-on-surface-variant';
}

export function severityLabel(severity) {
    const found = findSeverity(severity);
    return found ? `${found.numeral} – ${found.label}` : severity;
}

export function severityBadgeClasses(severity) {
    return findSeverity(severity)?.badge ?? 'bg-surface-container text-on-surface-variant';
}
