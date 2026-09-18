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
