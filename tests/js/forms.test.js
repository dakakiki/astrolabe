import { describe, expect, it } from 'vitest';

import { elements, files } from './helpers/templates';

// Labels of the buttons that walk away from a form or a confirmation. Not "Cancel appointment"
// or "Cancel the deletion": those are actions of their own.
const WALK_AWAY = [
    'common.cancel',
    'appointments.form.cancel',
    'clients.form.cancel',
    'related.cancel',
    'related.form.cancel',
    'services.form.cancel',
    'synastry.cancel',
    'appointments.detail.keep',
    'appointments.detail.keepRequest',
    'dashboard.requests.keep',
    'portal.appointments.keep',
    'portal.appointments.keepRequest',
];
const CANCEL = new RegExp(`\\bt\\('(${WALK_AWAY.join('|').replace(/\./g, '\\.')})'\\)`);

// A picker with nothing else in its row: the button is already on the left.
const ALONE = ['resources/js/components/SynastryPanel.vue'];

describe('form buttons', () => {
    it('marks every cancel button as the one that goes far left', () => {
        const found = files.flatMap(({ source }) => elements(source).filter((button) => CANCEL.test(button.inner)));
        expect(found.length).toBeGreaterThanOrEqual(19);

        const unmarked = files.flatMap(({ name, source }) =>
            ALONE.includes(name)
                ? []
                : elements(source)
                      .filter((button) => CANCEL.test(button.inner) && !/\bform-cancel\b/.test(button.open))
                      .map((button) => `${name}:${button.line}`),
        );

        expect(unmarked).toEqual([]);
    });

    it('puts the cancel button first in a .form-actions row', () => {
        const misplaced = [];
        for (const { name, source } of files) {
            for (const match of source.matchAll(/class="[^"]*\bform-cancel\b/g)) {
                const row = source.lastIndexOf('form-actions', match.index);
                const between = row === -1 ? '' : source.slice(row, match.index);
                // No other button opened between the row and the cancel button.
                if (
                    row === -1 ||
                    /<(button|RouterLink|a)\b/.test(between.replace(/<(button|RouterLink|a)\b[^<]*$/, ''))
                ) {
                    misplaced.push(`${name}:${source.slice(0, match.index).split('\n').length}`);
                }
            }
        }

        expect(misplaced).toEqual([]);
    });
});
