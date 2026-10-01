/**
 * The client's five-level Risk Triage scale - the single frontend source.
 * Must match App\Enums\Severity (value, label, numeral, meaning).
 * chart: one blue hue, light -> dark (steps of the sequential ramp in chartPalette.js).
 */
export const SEVERITIES = [
    { value: 'level_1_low', numeral: 'Level I', label: 'Low', meaning: 'Near miss / no harm or low-risk event', badge: 'bg-surface-container text-on-surface-variant', chart: '#b7d3f6' },
    { value: 'level_2_moderate', numeral: 'Level II', label: 'Moderate', meaning: 'Temporary harm or intervention required', badge: 'bg-amber-50 text-amber-900', chart: '#6da7ec' },
    { value: 'level_3_high', numeral: 'Level III', label: 'High', meaning: 'Significant harm, prolonged hospitalization or high-risk event', badge: 'bg-amber-200 text-amber-950', chart: '#2a78d6' },
    { value: 'level_4_critical', numeral: 'Level IV', label: 'Critical', meaning: 'Permanent or life-threatening harm', badge: 'bg-error-container text-on-error-container', chart: '#184f95' },
    { value: 'level_5_sentinel', numeral: 'Level V', label: 'Sentinel', meaning: 'Death or serious permanent harm / other agency-defined sentinel event', badge: 'bg-error text-on-error', chart: '#0d366b' },
];

export const severityOrder = SEVERITIES.map((severity) => severity.value);

export const HIGH_OR_ABOVE = ['level_3_high', 'level_4_critical', 'level_5_sentinel'];

export function findSeverity(value) {
    return SEVERITIES.find((severity) => severity.value === value) ?? null;
}
