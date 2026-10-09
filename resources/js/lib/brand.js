/**
 * A practice's colour in the client portal (docs/spec/12, Settings → Branding):
 * one colour in, the `--brand*` tokens for both themes out, every pairing at
 * WCAG AA (4.5:1). Buttons keep white text when the colour — or a shade at most
 * a quarter darker — carries it; a light colour (yellow, sky blue, brass) keeps
 * its own look with dark text instead; only a colour neither works on is
 * darkened further. Links and accents are lightened (night) or darkened (day)
 * until they read on that theme's surface. Status colours, text and surfaces
 * stay the system's. Pure, covered by Vitest.
 */

export const AA = 4.5;

/** `--surface` of each theme (resources/css/tokens.css). */
export const SURFACES = { night: '#131a2e', day: '#ffffff' };

/** Dark button text for light colours: the day theme's `--ink`. */
export const DARK_TEXT = '#10182b';

const WHITE = '#ffffff';
const BLACK = '#000000';

/** How much darker a colour may get before it stops looking like the practice's own. */
const MAX_DARKEN = 0.25;

/** "#3B46A8", "3b46a8" or "#34a" → "#3b46a8"; anything else → null. */
export function normaliseHex(value) {
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(value ?? '').trim());

    if (!match) return null;

    const digits = match[1].length === 3 ? [...match[1]].map((d) => d + d).join('') : match[1];

    return `#${digits.toLowerCase()}`;
}

function channels(hex) {
    return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
}

function toHex(values) {
    return `#${values
        .map((v) =>
            Math.round(Math.min(255, Math.max(0, v)))
                .toString(16)
                .padStart(2, '0'),
        )
        .join('')}`;
}

/** WCAG relative luminance of an sRGB colour. */
export function luminance(hex) {
    const [r, g, b] = channels(hex).map((v) => {
        const c = v / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two colours, 1–21. */
export function contrast(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

/** `weight` of `b` mixed into `a` (0 = a, 1 = b). */
export function mix(a, b, weight) {
    const [ca, cb] = [channels(a), channels(b)];

    return toHex(ca.map((v, i) => v + (cb[i] - v) * weight));
}

/** Moves `hex` towards `target` in small steps until it reaches AA against `background`. */
function readableOn(hex, background, target) {
    for (let step = 0; step <= 20; step++) {
        const candidate = mix(hex, target, step * 0.05);

        if (contrast(candidate, background) >= AA) return candidate;
    }

    return target;
}

/**
 * The button: its colour and its text. White text on the colour or a slightly
 * darker shade; otherwise dark text on the colour itself; otherwise white text
 * on whatever shade it takes.
 */
export function buttonColors(hex) {
    for (let step = 0; step * 0.05 <= MAX_DARKEN + 1e-9; step++) {
        const candidate = mix(hex, BLACK, step * 0.05);

        if (contrast(candidate, WHITE) >= AA) return { button: candidate, text: WHITE };
    }

    if (contrast(hex, DARK_TEXT) >= AA) return { button: hex, text: DARK_TEXT };

    return { button: readableOn(hex, WHITE, BLACK), text: WHITE };
}

/**
 * The tokens for both themes, and what happened to the colour on buttons:
 * `adjusted` (a darker shade is used) or `darkText`. Null for a missing or
 * malformed colour: the portal then keeps AstroLabe's.
 */
export function brandPalette(input) {
    const hex = normaliseHex(input);

    if (!hex) return null;

    const { button, text } = buttonColors(hex);
    const hover = mix(button, BLACK, text === WHITE ? 0.15 : 0.08);
    const darker = contrast(hover, text) >= AA ? hover : button;
    const nightText = readableOn(hex, SURFACES.night, WHITE);
    const dayText = readableOn(hex, SURFACES.day, BLACK);
    const buttons = { '--brand': button, '--brand-600': darker, '--on-brand': text };

    return {
        color: hex,
        button,
        text,
        adjusted: button !== hex,
        darkText: text !== WHITE,
        night: {
            ...buttons,
            '--brand-300': nightText,
            '--brand-100': mix(SURFACES.night, hex, 0.28),
            '--brand-050': mix(SURFACES.night, hex, 0.16),
            '--link': nightText,
        },
        day: {
            ...buttons,
            '--brand-300': mix(hex, WHITE, 0.45),
            '--brand-100': mix(WHITE, hex, 0.12),
            '--brand-050': mix(WHITE, hex, 0.06),
            '--link': dayText,
        },
    };
}

/**
 * The few base tokens of each theme a preview box needs to show the portal in
 * that theme next to the other one (Settings → Branding). Same values as
 * resources/css/tokens.css — a test keeps them in step.
 */
export const PREVIEW_THEMES = {
    night: {
        '--canvas': '#090d1b',
        '--surface': '#131a2e',
        '--surface-2': '#1a2340',
        '--ink': '#e8ecf8',
        '--ink-2': '#b4bfd8',
        '--ink-3': '#8590ac',
        '--line': '#273052',
        '--line-soft': '#1c2440',
        '--brand': '#5a61e0',
        '--brand-600': '#4950c9',
        '--brand-100': '#232c58',
        '--link': '#a3aaff',
        '--on-brand': '#ffffff',
    },
    day: {
        '--canvas': '#eef0f5',
        '--surface': '#ffffff',
        '--surface-2': '#f5f7fb',
        '--ink': '#10182b',
        '--ink-2': '#3b475f',
        '--ink-3': '#6b7889',
        '--line': '#dfe3ea',
        '--line-soft': '#eceef3',
        '--brand': '#3b46a8',
        '--brand-600': '#2f3a92',
        '--brand-100': '#e5e7fa',
        '--link': '#3b46a8',
        '--on-brand': '#ffffff',
    },
};

/** The tokens a preview box in `theme` sets: the theme's base, with the practice's colour over it. */
export function previewTokens(theme, palette) {
    return { ...PREVIEW_THEMES[theme], ...(palette ? palette[theme] : {}) };
}

/** CSS for a <style> element: the night tokens, and the day ones under `[data-theme='day']`. */
export function paletteCss(palette, selector = ':root') {
    if (!palette) return '';

    const block = (tokens) =>
        Object.entries(tokens)
            .map(([name, value]) => `${name}: ${value};`)
            .join(' ');

    return `${selector} { ${block(palette.night)} }\n${selector}[data-theme='day'] { ${block(palette.day)} }`;
}
