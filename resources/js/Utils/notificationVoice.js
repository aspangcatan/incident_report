// Reads real-time notifications aloud with the browser's built-in voice (Web Speech API).
// Browsers only allow speech after the person has interacted with the page, so the
// first click or key press "warms up" the voice for the rest of the session.

import { findSeverity } from './severities.js';

const STORAGE_KEY = 'csmc.notificationVoice';
// Keep the queue short: a burst of notifications shouldn't talk for a minute.
const MAX_QUEUED = 3;
let queued = 0;

const supported = () => typeof window !== 'undefined' && 'speechSynthesis' in window;

function warmUp() {
    if (!supported()) return;
    // An empty utterance inside a user gesture unlocks speech in Chrome and Edge.
    window.speechSynthesis.speak(new SpeechSynthesisUtterance(''));
}

// Call once (the layout does).
export function prepareNotificationVoice() {
    ['pointerdown', 'keydown'].forEach((event) => window.addEventListener(event, warmUp, { once: true, passive: true }));
}

export function isVoiceMuted() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'off';
    } catch {
        return false;
    }
}

export function setVoiceMuted(muted) {
    try {
        window.localStorage.setItem(STORAGE_KEY, muted ? 'off' : 'on');
    } catch {
        // Storage blocked: the setting lasts until the page reloads.
    }
    if (muted && supported()) window.speechSynthesis.cancel();
}

/**
 * A short spoken line per notification type, e.g. "New notification, Incident Level: Critical".
 * The full message stays on screen in the toast.
 */
export function spokenText(notification) {
    const level = findSeverity(notification?.data?.severity)?.label;
    const phrase = level ? `Incident Level: ${level}` : (PHRASES[notification?.type] ?? null);

    return phrase ? `New notification, ${phrase}` : 'New notification';
}

const PHRASES = {
    IncidentSubmittedNotification: 'New incident report',
    IncidentReadyForReviewNotification: 'Incident ready for triage',
    IncidentReviewedNotification: 'Your report was reviewed',
    IncidentReturnedForRevisionNotification: 'Report returned for revision',
    IncidentReturnedToDepartmentNotification: 'Incident returned to the department',
    IncidentAssignedNotification: 'Investigation assigned to you',
    DeadlineReminderNotification: 'Deadline reminder',
    IncidentEscalationNotification: 'Escalation, deadline missed',
    EffectivenessCheckDueNotification: 'Effectiveness check due',
    ReadyForClosureNotification: 'Ready for closure',
    ClosureApprovalNeededNotification: 'Closure approval needed',
    CommitteeSignOffNeededNotification: 'Committee sign-off needed',
    SafetyAlertNotification: 'Safety alert',
    RecurrenceReviewNotification: 'Recurrence review',
};

function englishVoice() {
    const voices = window.speechSynthesis.getVoices();
    return voices.find((voice) => voice.lang === 'en-PH')
        ?? voices.find((voice) => voice.lang?.startsWith('en') && voice.localService)
        ?? voices.find((voice) => voice.lang?.startsWith('en'))
        ?? null;
}

/** Says the text; urgent alerts are spoken a little slower. */
export function speak(text, urgent = false) {
    if (!supported() || isVoiceMuted() || !text) return;

    const synth = window.speechSynthesis;
    if (queued >= MAX_QUEUED) return;

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'en-US';
    utterance.voice = englishVoice();
    utterance.rate = urgent ? 0.9 : 1;
    utterance.volume = 1;
    utterance.onend = utterance.onerror = () => {
        queued = Math.max(0, queued - 1);
    };
    queued++;
    synth.speak(utterance);
}
