/**
 * Zodiac arithmetic and glyphs for display. Longitudes arrive from the server in
 * degrees 0–360 in the chart's own zodiac (tropical or sidereal).
 */

// U+FE0E asks for the text form of a symbol, so signs never turn into emoji.
const TEXT = '︎';

export const SIGNS = [
    { key: 'aries', glyph: '♈', element: 'fire' },
    { key: 'taurus', glyph: '♉', element: 'earth' },
    { key: 'gemini', glyph: '♊', element: 'air' },
    { key: 'cancer', glyph: '♋', element: 'water' },
    { key: 'leo', glyph: '♌', element: 'fire' },
    { key: 'virgo', glyph: '♍', element: 'earth' },
    { key: 'libra', glyph: '♎', element: 'air' },
    { key: 'scorpio', glyph: '♏', element: 'water' },
    { key: 'sagittarius', glyph: '♐', element: 'fire' },
    { key: 'capricorn', glyph: '♑', element: 'earth' },
    { key: 'aquarius', glyph: '♒', element: 'air' },
    { key: 'pisces', glyph: '♓', element: 'water' },
].map((sign) => ({ ...sign, glyph: sign.glyph + TEXT }));

export const BODY_GLYPHS = Object.fromEntries(
    Object.entries({
        sun: '☉',
        moon: '☽',
        mercury: '☿',
        venus: '♀',
        mars: '♂',
        jupiter: '♃',
        saturn: '♄',
        uranus: '♅',
        neptune: '♆',
        pluto: '♇',
        true_node: '☊',
        mean_node: '☊',
    }).map(([body, glyph]) => [body, glyph + TEXT]),
);

/**
 * Sign, whole degrees and whole minutes of a longitude. Minutes are truncated,
 * never rounded, the way astrologers read them: 29°59.7′ stays in its sign.
 */
export function splitLongitude(longitude) {
    const normalized = ((longitude % 360) + 360) % 360;
    const totalMinutes = Math.floor(normalized * 60 + 1e-9);
    const sign = Math.floor(totalMinutes / 1800);
    const inSign = totalMinutes - sign * 1800;

    return { sign: SIGNS[sign], degree: Math.floor(inSign / 60), minute: inSign % 60 };
}

/** "22°57′" within the sign. */
export function formatDegrees(longitude) {
    const { degree, minute } = splitLongitude(longitude);

    return `${degree}°${String(minute).padStart(2, '0')}′`;
}
