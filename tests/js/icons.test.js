import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { icons } from '@/lib/icons';

const root = fileURLToPath(new URL('../../', import.meta.url));

function vueFiles(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) return vueFiles(path);
        return entry.name.endsWith('.vue') ? [path] : [];
    });
}

const files = vueFiles(join(root, 'resources/js')).map((path) => ({
    name: relative(root, path).replace(/\\/g, '/'),
    source: readFileSync(path, 'utf8'),
}));

// End of the opening tag that starts at `start`, skipping ">" inside attribute values.
function openTagEnd(source, start) {
    let quote = null;
    for (let i = start + 1; i < source.length; i++) {
        const c = source[i];
        if (quote) {
            if (c === quote) quote = null;
        } else if (c === '"' || c === "'") {
            quote = c;
        } else if (c === '>') {
            return i + 1;
        }
    }
    return -1;
}

// Every element with the .btn class: buttons, links styled as buttons, upload labels.
function buttons(source) {
    const found = [];
    const template = source.indexOf('<template');
    const tags = /<(button|RouterLink|a|label)(?=[\s>])/g;
    let match;
    while ((match = tags.exec(source))) {
        if (match.index < template) continue;
        const end = openTagEnd(source, match.index);
        const open = source.slice(match.index, end);
        const classes = (open.match(/\sclass="([^"]*)"/) || [])[1] ?? '';
        if (open.endsWith('/>') || !/(?:^|\s)btn(?:\s|$)/.test(classes)) continue;
        const inner = source.slice(end, source.indexOf(`</${match[1]}`, end));
        found.push({ line: source.slice(0, match.index).split('\n').length, inner });
    }
    return found;
}

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
