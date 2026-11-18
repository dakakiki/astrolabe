import { describe, expect, it } from 'vitest';

import { icons } from '@/lib/icons';

import { elements as buttons, files } from './helpers/templates';

// Buttons whose content is the choice itself — a time slot, a practice with its logo.
const WITHOUT_ICON = [
    'resources/js/portal/components/SlotPicker.vue',
    'resources/js/portal/pages/ChoosePracticePage.vue',
];

describe('icons', () => {
    it('maps every name to a drawable component', () => {
        for (const [name, component] of Object.entries(icons)) {
            expect(component, name).toBeTruthy();
            expect(['object', 'function'], name).toContain(typeof component);
        }
    });

    it('gives every button an icon', () => {
        const missing = files.flatMap(({ name, source }) =>
            WITHOUT_ICON.includes(name)
                ? []
                : buttons(source)
                      .filter((button) => !button.inner.includes('<AppIcon'))
                      .map((button) => `${name}:${button.line}`),
        );

        expect(missing).toEqual([]);
    });

    it('uses only names from lib/icons.js', () => {
        const used = [];
        for (const { name, source } of files) {
            for (const match of source.matchAll(/<AppIcon\s[^>]*?(?<!:)\bname="([^"]+)"/g)) {
                used.push([name, match[1]]);
            }
            // The results of a bound name (`:name="open ? 'less' : 'more'"`, `icon ?? 'confirm'`)
            for (const match of source.matchAll(/<AppIcon\s[^>]*?:name="([^"]+)"/g)) {
                for (const literal of match[1].matchAll(/(?:\?\??|:)\s*'([^']+)'/g)) used.push([name, literal[1]]);
            }
            // Names kept in data: navigation items, editor buttons, admin actions, portal sections
            for (const match of source.matchAll(/\bicon: '([^']+)'/g)) used.push([name, match[1]]);
            const sections = source.match(/const sectionIcons = \{([^}]*)\}/);
            for (const literal of sections?.[1].matchAll(/: '([^']+)'/g) ?? []) used.push([name, literal[1]]);
        }

        expect(used.length).toBeGreaterThan(250);
        expect(used.filter(([, icon]) => !(icon in icons))).toEqual([]);
    });

    it('leaves the old glyphs out of buttons and navigation', () => {
        const layout = files.find(({ name }) => name === 'resources/js/layouts/AppLayout.vue').source;

        expect(layout).not.toMatch(/icon: '[^a-zA-Z]/);
        expect(layout).not.toContain('☰');
        for (const { name, source } of files) {
            for (const button of buttons(source)) {
                expect(button.inner, `${name}:${button.line}`).not.toMatch(/^\s*\+ |[✕×‹›☰]/);
            }
        }
    });
});
