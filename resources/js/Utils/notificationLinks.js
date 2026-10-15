// Where a notification leads, from its stored data. Used by the Notifications page and the real-time toasts.
export function notificationUrl(data = {}) {
    if (data.safety_alert_id) return `/safety-alerts/${data.safety_alert_id}`;
    if (data.recurrence_review_id) return `/recurrence-reviews/${data.recurrence_review_id}`;
    if (data.incident_id) {
        const tab = data.tab ? `?tab=${data.tab}` : '';
        return `/incidents/${data.incident_id}${tab}`;
    }
    return null;
}

// Notification class names that deserve a red, sticky alert.
export function isUrgentNotification(type = '') {
    return /Escalation|Severity|HighRisk/.test(type);
}
