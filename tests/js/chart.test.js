import { describe, expect, it } from 'vitest';

import {
    arcPath,
    byOrb,
    describeChart,
    formatOrb,
    houseMiddle,
    houseOf,
    normalize,
    polar,
    spread,
    wheelRotation,
} from '../../resources/js/lib/chart';

const close = (a, b) => Math.abs(a - b) < 1e-6;

/** Angular distance, the shorter way round. */
const distance = (a, b) => Math.abs(((((a - b) % 360) + 540) % 360) - 180);

describe('polar', () => {
    it('puts the rotation point at nine o’clock and runs the zodiac anticlockwise', () => {
        const [x, y] = polar(218, 100, 218, 300);
        expect(close(x, 200) && close(y, 300)).toBe(true);

        // 90° further along the zodiac is straight down (the IC side), not up.
        const [, below] = polar(308, 100, 218, 300);
        expect(close(below, 400)).toBe(true);

        // 90° back is the top: where the Midheaven usually sits.
        const [, above] = polar(128, 100, 218, 300);
        expect(close(above, 200)).toBe(true);
    });
});

describe('wheelRotation', () => {
    it('starts from the Ascendant, or from 0° Aries without angles', () => {
        expect(wheelRotation({ angles: { asc: 218.18 } })).toBe(218.18);
        expect(wheelRotation({ angles: null })).toBe(0);
    });
});

describe('spread', () => {
    it('leaves points that are far apart where they are', () => {
        const display = spread(
            [
                { key: 'sun', longitude: 10 },
                { key: 'moon', longitude: 100 },
            ],
            8,
        );

        expect(display).toEqual({ sun: 10, moon: 100 });
    });

    it('fans out a cluster evenly around its true centre', () => {
        const display = spread(
            [
                { key: 'sun', longitude: 112.95 },
                { key: 'mars', longitude: 113.74 },
                { key: 'mercury', longitude: 115 },
            ],
            8,
        );

        const centre = (112.95 + 113.74 + 115) / 3;
        expect(close(display.sun, centre - 8)).toBe(true);
        expect(close(display.mars, centre)).toBe(true);
        expect(close(display.mercury, centre + 8)).toBe(true);
    });

    it('keeps every pair at least the minimum gap apart, in zodiac order', () => {
        const points = [0.5, 2, 3, 3.5, 7, 40, 44, 45, 200, 355, 358].map((longitude, i) => ({ key: `p${i}`, longitude }));
        const display = spread(points, 6);
        const shown = points.map((point) => display[point.key]);

        for (let i = 0; i < shown.length; i++) {
            for (let j = i + 1; j < shown.length; j++) {
                expect(distance(shown[i], shown[j])).toBeGreaterThanOrEqual(6 - 1e-9);
            }
        }
    });

    it('handles clusters across 0° Aries', () => {
        const display = spread(
            [
                { key: 'a', longitude: 358 },
                { key: 'b', longitude: 1 },
                { key: 'c', longitude: 180 },
            ],
            8,
        );

        expect(close(distance(display.a, 359.5), 4)).toBe(true);
        expect(close(distance(display.b, 359.5), 4)).toBe(true);
        expect(close(normalize(display.b - display.a), 8)).toBe(true);
        expect(display.c).toBe(180);
    });
});

describe('arcPath', () => {
    it('draws a short arc in the direction of the signs', () => {
        expect(arcPath(10, 23, 100, 0, 300)).toMatch(/ A 100 100 0 0 0 /);
    });

    it('uses the large arc flag only beyond 180°', () => {
        expect(arcPath(0, 200, 100, 0, 300)).toMatch(/ A 100 100 0 1 0 /);
    });
});

describe('houseOf', () => {
    const placidus = [218.18, 246.9, 281.22, 318.2, 351.1, 17.38, 38.18, 66.9, 101.22, 138.2, 171.1, 197.38];

    it('matches the server: from a cusp up to, not including, the next', () => {
        expect(houseOf(218.18, placidus)).toBe(1);
        expect(houseOf(138.2, placidus)).toBe(10);
        expect(houseOf(246.9, placidus)).toBe(2);
        expect(houseOf(0, placidus)).toBe(5);
    });

    it('can put the Midheaven outside the tenth house in Whole Sign', () => {
        const wholeSign = [210, 240, 270, 300, 330, 0, 30, 60, 90, 120, 150, 180];

        expect(houseOf(138.2, wholeSign)).toBe(10);
        expect(houseOf(115, wholeSign)).toBe(9);
    });
});

describe('houseMiddle', () => {
    it('finds the middle of a house that spans 0° Aries', () => {
        const cusps = [218.18, 246.9, 281.22, 318.2, 351.1, 17.38, 38.18, 66.9, 101.22, 138.2, 171.1, 197.38];

        expect(close(houseMiddle(cusps, 4), 4.24)).toBe(true);
        expect(close(houseMiddle(cusps, 11), 207.78)).toBe(true);
    });
});

describe('formatOrb', () => {
    it('shows degrees and truncated minutes', () => {
        expect(formatOrb(2.5)).toBe('2°30′');
        expect(formatOrb(0.9999)).toBe('0°59′');
        expect(formatOrb(7)).toBe('7°00′');
    });
});

describe('byOrb', () => {
    it('sorts from the tightest aspect and leaves the input alone', () => {
        const aspects = [{ orb: 3 }, { orb: 0.2 }, { orb: 1 }];

        expect(byOrb(aspects).map((a) => a.orb)).toEqual([0.2, 1, 3]);
        expect(aspects[0].orb).toBe(3);
        expect(byOrb(null)).toEqual([]);
    });
});

describe('describeChart', () => {
    const t = (key, params = {}) =>
        ({
            'chart.angles.asc': 'Ascendant',
            'chart.angles.mc': 'Midheaven',
            'chart.retrograde': 'Retrograde',
            'chart.describe.house': `house ${params.house}`,
            'chart.describe.range': `between ${params.from} and ${params.to}`,
            'chart.describe.aspects': `${params.count} aspects`,
        })[key] ?? key.split('.').pop();

    it('reads the angles, each body with its house and motion, and the aspect count', () => {
        const text = describeChart(
            {
                angles: { asc: 218.18, mc: 138.2 },
                positions: [
                    { body: 'sun', longitude: 112.95, house: 9, retrograde: false },
                    { body: 'saturn', longitude: 231.55, house: 1, retrograde: true },
                ],
                aspects: [{}, {}],
            },
            t,
        );

        expect(text).toBe(
            'Ascendant 8°10′ scorpio. Midheaven 18°12′ leo. sun 22°57′ cancer, house 9. ' +
                'saturn 21°33′ scorpio, house 1, retrograde. 2 aspects.',
        );
    });

    it('gives the Moon as a span when the birth time is unknown', () => {
        const text = describeChart(
            {
                angles: null,
                moon_range: { from: 80, to: 93 },
                positions: [{ body: 'moon', longitude: 86, house: null, retrograde: false }],
                aspects: [],
            },
            t,
        );

        expect(text).toBe('moon between 20°00′ gemini and 3°00′ cancer. 0 aspects.');
    });
});
