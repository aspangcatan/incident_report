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

const SEVERITY_LABELS = {
    level_1_low: 'Low Risk',
    level_2_moderate: 'Moderate Risk',
    level_3_high: 'High Severity',
    level_4_critical_sentinel: 'Critical / Sentinel',
};

const SEVERITY_CLASSES = {
    level_1_low: 'bg-surface-container text-on-surface-variant',
    level_2_moderate: 'bg-amber-50 text-amber-900',
    level_3_high: 'bg-amber-200 text-amber-950',
    level_4_critical_sentinel: 'bg-error-container text-on-error-container',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status;
}

export function statusBadgeClasses(status) {
    return STATUS_CLASSES[status] ?? 'bg-surface-container text-on-surface-variant';
}

export function severityLabel(severity) {
    return SEVERITY_LABELS[severity] ?? severity;
}

export function severityBadgeClasses(severity) {
    return SEVERITY_CLASSES[severity] ?? 'bg-surface-container text-on-surface-variant';
}
