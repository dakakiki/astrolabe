import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import {
    AA,
    PREVIEW_THEMES,
    SURFACES,
    brandPalette,
    DARK_TEXT,
    buttonColors,
    contrast,
    mix,
    normaliseHex,
    paletteCss,
    previewTokens,
} from '../../resources/js/lib/brand';

describe('normaliseHex', () => {
    it('reads the usual ways of writing a colour', () => {
        expect(normaliseHex('#3B46A8')).toBe('#3b46a8');
        expect(normaliseHex('3b46a8')).toBe('#3b46a8');
        expect(normaliseHex(' #34a ')).toBe('#3344aa');
    });

    it('refuses anything else', () => {
        for (const value of ['', null, undefined, 'blue', '#12345', '#1234567', 'rgb(1,2,3)']) {
            expect(normaliseHex(value)).toBeNull();
        }
    });
});

describe('contrast', () => {
    it('follows the WCAG ratio', () => {
        expect(contrast('#ffffff', '#000000')).toBeCloseTo(21, 5);
        expect(contrast('#777777', '#777777')).toBeCloseTo(1, 5);
        // The AA threshold for white text sits around #767676.
        expect(contrast('#767676', '#ffffff')).toBeGreaterThanOrEqual(AA);
        expect(contrast('#777777', '#ffffff')).toBeLessThan(AA);
    });

    it('mixes channel by channel', () => {
        expect(mix('#000000', '#ffffff', 0.5)).toBe('#808080');
        expect(mix('#102030', '#102030', 0.7)).toBe('#102030');
    });
});

describe('brandPalette', () => {
    it('keeps a colour white text reads well on', () => {
        const palette = brandPalette('#3b46a8');

        expect(palette.adjusted).toBe(false);
        expect(palette.button).toBe('#3b46a8');
        expect(palette.night['--brand']).toBe('#3b46a8');
    });

    it('keeps a light colour with dark button text', () => {
        for (const color of ['#ffff00', '#f5c518', '#9ad3ff', '#ffffff', '#e5b25c']) {
            const palette = brandPalette(color);

            expect(palette.button, color).toBe(color);
            expect(palette.darkText, color).toBe(true);
            expect(palette.night['--on-brand'], color).toBe(DARK_TEXT);
            expect(contrast(palette.button, palette.text), color).toBeGreaterThanOrEqual(AA);
            expect(contrast(palette.night['--brand-600'], palette.text), color).toBeGreaterThanOrEqual(AA);
        }
    });

    it('darkens a colour a little for white text when that is enough', () => {
        const red = brandPalette('#ff0000');

        expect(red.adjusted).toBe(true);
        expect(red.darkText).toBe(false);
        expect(contrast(red.button, '#ffffff')).toBeGreaterThanOrEqual(AA);
        expect(contrast(red.button, '#ff0000')).toBeLessThan(1.5);
    });

    it('never pairs a button colour with text it cannot carry', () => {
        for (let r = 0; r < 256; r += 51) {
            for (let g = 0; g < 256; g += 51) {
                for (let b = 0; b < 256; b += 51) {
                    const color = '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('');
                    const { button, text } = buttonColors(color);

                    expect(contrast(button, text), color).toBeGreaterThanOrEqual(AA);
                }
            }
        }
    });

    it('makes links readable on both themes', () => {
        for (const color of ['#000080', '#3b46a8', '#ffff00', '#e5b25c', '#111111', '#ff0000']) {
            const palette = brandPalette(color);

            expect(contrast(palette.night['--link'], SURFACES.night), color).toBeGreaterThanOrEqual(AA);
            expect(contrast(palette.day['--link'], SURFACES.day), color).toBeGreaterThanOrEqual(AA);
            expect(contrast(palette.night['--on-brand'], palette.night['--brand']), color).toBeGreaterThanOrEqual(AA);
        }
    });

    it('is nothing without a valid colour, so the portal keeps AstroLabe’s', () => {
        expect(brandPalette(null)).toBeNull();
        expect(brandPalette('not a colour')).toBeNull();
        expect(paletteCss(null)).toBe('');
    });

    it('writes both themes as CSS', () => {
        const css = paletteCss(brandPalette('#aa3355'));

        expect(css).toContain(':root { --brand: ');
        expect(css).toContain(":root[data-theme='day'] { --brand: ");
        expect(buttonColors('#aa3355')).toEqual({ button: '#aa3355', text: '#ffffff' });
    });
});

describe('preview themes', () => {
    // The preview boxes in Settings → Branding repeat a few token values; they must match tokens.css.
    const css = readFileSync(new URL('../../resources/css/tokens.css', import.meta.url), 'utf8');
    const block = (selector) => {
        const start = css.indexOf(selector);
        const body = css.slice(start, css.indexOf('}', start));

        return Object.fromEntries([...body.matchAll(/(--[\w-]+):\s*(#[0-9a-f]{6})/gi)].map(([, name, value]) => [name, value.toLowerCase()]));
    };
    const night = block(':root {');
    const day = { ...night, ...block(":root[data-theme='day'] {") };

    it('match the night and day tokens', () => {
        for (const [name, value] of Object.entries(PREVIEW_THEMES.night)) expect(value, name).toBe(night[name]);
        for (const [name, value] of Object.entries(PREVIEW_THEMES.day)) expect(value, name).toBe(day[name]);
        expect(SURFACES.night).toBe(night['--surface']);
        expect(SURFACES.day).toBe(day['--surface']);
    });

    it('lay the practice’s colour over the theme', () => {
        const palette = brandPalette('#aa3355');

        expect(previewTokens('day', palette)['--brand']).toBe(palette.day['--brand']);
        expect(previewTokens('day', palette)['--surface']).toBe('#ffffff');
        expect(previewTokens('night', null)['--brand']).toBe(night['--brand']);
    });
});
