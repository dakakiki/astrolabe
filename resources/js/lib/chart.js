/**
 * Geometry and wording for the natal chart wheel and its tables. Everything
 * astrological (houses, aspects, orbs) is calculated on the server; this file
 * only decides where things go on screen and how they read.
 */
import { formatDegrees, splitLongitude } from '@/lib/zodiac';

// U+FE0E asks for the text form of a symbol, so glyphs never turn into emoji.
const TEXT = '︎';

export const ASPECT_GLYPHS = Object.fromEntries(
    Object.entries({
        conjunction: '☌',
        sextile: '⚹',
        square: '□',
        trine: '△',
        opposition: '☍',
        quincunx: '⚻',
        semisextile: '⚺',
        semisquare: '∠',
        sesquisquare: '⚼',
    }).map(([type, glyph]) => [type, glyph + TEXT]),
);

/** The order aspect types are listed in, e.g. in the legend: by angle. */
export const ASPECT_ORDER = [
    'conjunction',
    'semisextile',
    'semisquare',
    'sextile',
    'square',
    'trine',
    'sesquisquare',
    'quincunx',
    'opposition',
];

export const ANGLES = ['asc', 'mc', 'dsc', 'ic'];

export function normalize(degrees) {
    const d = degrees % 360;
    return d < 0 ? d + 360 : d;
}

/**
 * The longitude drawn at the nine o'clock position: the Ascendant, the way a
 * chart is read, or 0° Aries when there are no angles (unknown birth time).
 */
export function wheelRotation(chart) {
    return chart.angles?.asc ?? 0;
}

/**
 * Screen point of a longitude on a circle of the given radius. The zodiac runs
 * anticlockwise; SVG's y axis points down.
 */
export function polar(longitude, radius, rotation, center) {
    const theta = ((180 + longitude - rotation) * Math.PI) / 180;

    return [center + radius * Math.cos(theta), center - radius * Math.sin(theta)];
}

/**
 * Display longitudes that keep glyphs at least `minGap` degrees apart. Points
 * closer than that form a cluster, laid out evenly around the mean of their
 * true positions; clusters that then touch merge. The circle is opened at its
 * widest gap, so no cluster straddles the cut.
 *
 * @param {{key: string, longitude: number}[]} points
 * @returns {Record<string, number>} key → display longitude
 */
export function spread(points, minGap) {
    const n = points.length;
    if (n === 0) return {};

    const sorted = points
        .map((point) => ({ key: point.key, longitude: normalize(point.longitude) }))
        .sort((a, b) => a.longitude - b.longitude);

    let cut = 0;
    let widest = -1;
    sorted.forEach((point, i) => {
        const next = sorted[(i + 1) % n];
        const gap = normalize(next.longitude - point.longitude) || (n === 1 ? 360 : 0);
        if (gap > widest) {
            widest = gap;
            cut = (i + 1) % n;
        }
    });

    const base = sorted[cut].longitude;
    const line = [...sorted.slice(cut), ...sorted.slice(0, cut)].map((point) => ({
        key: point.key,
        position: point.longitude < base ? point.longitude + 360 : point.longitude,
    }));

    let clusters = line.map((point) => ({ keys: [point.key], sum: point.position }));
    const start = (cluster) => cluster.sum / cluster.keys.length - ((cluster.keys.length - 1) * minGap) / 2;
    const end = (cluster) => start(cluster) + (cluster.keys.length - 1) * minGap;

    for (let merged = true; merged;) {
        merged = false;
        for (let i = 0; i < clusters.length - 1; i++) {
            if (start(clusters[i + 1]) - end(clusters[i]) < minGap - 1e-9) {
                const [a, b] = [clusters[i], clusters[i + 1]];
                clusters = [
                    ...clusters.slice(0, i),
                    { keys: [...a.keys, ...b.keys], sum: a.sum + b.sum },
                    ...clusters.slice(i + 2),
                ];
                merged = true;
                break;
            }
        }
    }

    const display = {};
    for (const cluster of clusters) {
        cluster.keys.forEach((key, i) => {
            display[key] = normalize(start(cluster) + i * minGap);
        });
    }

    return display;
}

/**
 * An SVG arc along the zodiac from one longitude to another, in the direction
 * of the signs — for the Moon's span when the birth time is unknown.
 */
export function arcPath(from, to, radius, rotation, center) {
    const span = normalize(to - from);
    const [x1, y1] = polar(from, radius, rotation, center);
    const [x2, y2] = polar(to, radius, rotation, center);

    // Anticlockwise on screen is sweep-flag 0 in SVG.
    return `M ${x1.toFixed(2)} ${y1.toFixed(2)} A ${radius} ${radius} 0 ${span > 180 ? 1 : 0} 0 ${x2.toFixed(2)} ${y2.toFixed(2)}`;
}

/**
 * The house (1–12) a longitude falls in, as the server decides it for bodies:
 * from a cusp up to, not including, the next. Needed for the angles, which in
 * Equal or Whole Sign houses need not sit on a cusp — the Midheaven can fall
 * in the 9th or 11th house.
 */
export function houseOf(longitude, cusps) {
    const at = normalize(longitude);

    for (let i = 0; i < 12; i++) {
        if (normalize(at - cusps[i]) < normalize(cusps[(i + 1) % 12] - cusps[i])) return i + 1;
    }

    return 1;
}

/** The middle of a house: halfway from its cusp to the next one, across 0° Aries if needed. */
export function houseMiddle(cusps, index) {
    const from = cusps[index];
    const to = cusps[(index + 1) % 12];

    return normalize(from + normalize(to - from) / 2);
}

/** An orb as degrees and whole minutes, truncated: "2°34′". */
export function formatOrb(orb) {
    const minutes = Math.floor(orb * 60 + 1e-9);

    return `${Math.floor(minutes / 60)}°${String(minutes % 60).padStart(2, '0')}′`;
}

/** Aspects from the tightest to the widest. */
export function byOrb(aspects) {
    return [...(aspects ?? [])].sort((a, b) => a.orb - b.orb);
}

/** Stroke weight for an aspect line: the tighter the aspect, the stronger the line. */
export function aspectWeight(orb) {
    if (orb < 1) return 'tight';
    if (orb < 3) return 'close';
    return 'wide';
}

/**
 * "22°57′ Cancer" — a longitude with its sign's name, for text alternatives.
 *
 * @param {(key: string) => string} t
 */
export function longitudeText(longitude, t) {
    return `${formatDegrees(longitude)} ${t(`signs.${splitLongitude(longitude).sign.key}`)}`;
}

/**
 * The chart in words, for screen readers (docs/spec/11): the angles, every
 * body with its sign, house and motion, then how many aspects the table lists.
 *
 * @param {(key: string, params?: object) => string} t
 */
export function describeChart(chart, t) {
    const parts = [];

    if (chart.angles) {
        parts.push(`${t('chart.angles.asc')} ${longitudeText(chart.angles.asc, t)}`);
        parts.push(`${t('chart.angles.mc')} ${longitudeText(chart.angles.mc, t)}`);
    }

    for (const position of chart.positions) {
        const where =
            position.body === 'moon' && chart.moon_range
                ? t('chart.describe.range', {
                      from: longitudeText(chart.moon_range.from, t),
                      to: longitudeText(chart.moon_range.to, t),
                  })
                : longitudeText(position.longitude, t);

        let text = `${t(`bodies.${position.body}`)} ${where}`;
        if (position.house) text += `, ${t('chart.describe.house', { house: position.house })}`;
        if (position.retrograde) text += `, ${t('chart.retrograde').toLowerCase()}`;
        parts.push(text);
    }

    if (chart.aspects) {
        parts.push(t('chart.describe.aspects', { count: chart.aspects.length }, chart.aspects.length));
    }

    return `${parts.join('. ')}.`;
}
