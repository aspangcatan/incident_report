/**
 * The dataviz skill's validated default categorical palette (8 hues, fixed
 * order - the order is the CVD-safety mechanism, not cosmetic, so don't
 * reorder or cycle it). Not added to tailwind.config.js since this app has
 * no categorical/status color set of its own to extend - kept here as the
 * one shared source of truth so every chart component references the same
 * array instead of each hardcoding its own copy.
 */
export const categoricalPalette = [
    '#2a78d6', // slot 1: blue
    '#eb6834', // slot 2: orange
    '#1baf7a', // slot 3: aqua
    '#eda100', // slot 4: yellow
    '#e87ba4', // slot 5: magenta
    '#4a3aa7', // slot 6: violet
    '#e34948', // slot 7: red
    '#008300', // slot 8: green
];

/** Day/night shift colors, reusing categorical slots 1 and 2 (blue/orange) - an already-validated adjacent pair. */
export const dayShiftColor = categoricalPalette[0];
export const nightShiftColor = categoricalPalette[1];

/**
 * Severity is ordered (Low -> Sentinel), so it uses one hue light->dark, not
 * categorical colors: blue ramp steps 250/400/550/700, validated with
 * validate_palette.js --ordinal on the white card surface (all checks pass).
 * "Not yet assessed" is a neutral gray, outside the ramp.
 */
export const severityRamp = {
    level_1_low: '#86b6ef',
    level_2_moderate: '#3987e5',
    level_3_high: '#1c5cab',
    level_4_critical_sentinel: '#0d366b',
    unassessed: '#c5c5d3',
};

export const severityOrder = ['level_1_low', 'level_2_moderate', 'level_3_high', 'level_4_critical_sentinel'];

/** Full sequential blue ramp (100 -> 700) for magnitude, e.g. heatmap cells. */
export const sequentialBlue = ['#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281', '#0d366b'];
