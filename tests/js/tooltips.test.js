import { describe, expect, it } from 'vitest';

import appMessages from '@/i18n/locales/en.json';
import { placeTooltip } from '@/lib/tooltip';
import portalMessages from '@/portal/locales/en.json';

import { elements, files, lookup } from './helpers/templates';

// Everything that acts like a button: .btn, the round icon buttons, the menu and account buttons,
// the portal's service cards.
const BUTTONS = /(?:^|\s)(btn|icon-btn|menu-btn|avatar|svc-card)(?:\s|$)/;

// Times to pick in the portal: the button is the choice itself.
const WITHOUT_TIP = ['resources/js/portal/components/SlotPicker.vue'];

const viewport = { width: 1000, height: 700 };
const size = { width: 120, height: 30 };

describe('tooltip placement', () => {
    it('sits centred above the button', () => {
        const place = placeTooltip({ top: 300, bottom: 334, left: 400, width: 100 }, size, viewport);

        expect(place).toEqual({ top: 262, left: 390, side: 'top' });
    });

    it('goes below a button at the top of the screen', () => {
        const place = placeTooltip({ top: 10, bottom: 44, left: 400, width: 100 }, size, viewport);

        expect(place).toEqual({ top: 52, left: 390, side: 'bottom' });
    });

    it('stays inside the screen at both edges', () => {
        expect(placeTooltip({ top: 300, bottom: 334, left: 0, width: 40 }, size, viewport).left).toBe(8);
        expect(placeTooltip({ top: 300, bottom: 334, left: 970, width: 30 }, size, viewport).left).toBe(872);
    });
});

describe('tooltips on buttons', () => {
    it('gives every button a tooltip', () => {
        const missing = files.flatMap(({ name, source }) =>
            WITHOUT_TIP.includes(name)
                ? []
                : elements(source, BUTTONS)
                      .filter((button) => !/\sv-tip=/.test(button.open))
                      .map((button) => `${name}:${button.line}`),
        );

        expect(missing).toEqual([]);
    });

    it('has a text for every tooltip it names', () => {
        const unknown = [];
        for (const { name, source } of files) {
            const messages = name.startsWith('resources/js/portal/') ? portalMessages : appMessages;
            for (const tip of source.matchAll(/\sv-tip="([^"]+)"/g)) {
                for (const key of tip[1].matchAll(/\bt\('([^']+)'/g)) {
                    if (typeof lookup(messages, key[1]) !== 'string') unknown.push(`${name}: ${key[1]}`);
                }
            }
        }

        expect(unknown).toEqual([]);
    });

    it('has a text for the tooltips named at run time', () => {
        // Admin account-help actions: tips.admin.actions.<key>
        for (const key of Object.keys(appMessages.admin.astrologer.actions)) {
            expect(typeof appMessages.tips.admin.actions[key], key).toBe('string');
        }
        // Editor toolbar: tips.<button.label>
        const editor = files.find(({ name }) => name.endsWith('/RichTextEditor.vue')).source;
        for (const [, label] of editor.matchAll(/label: '(editor\.[^']+)'/g)) {
            expect(typeof lookup(appMessages.tips, label), label).toBe('string');
        }
    });
});
