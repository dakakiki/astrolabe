import { describe, expect, it } from 'vitest';

import {
    insertPlaceholder,
    placeholderProblems,
    shownText,
    textsPayload,
    unsavedTexts,
} from '../../resources/js/lib/notificationTexts';

const subject = {
    key: 'portal.mail.booking.confirmed.subject',
    default: 'Confirmed: :when',
    value: null,
    placeholders: ['when'],
    required: [],
};
const code = {
    key: 'portal.mail.sign_in.code',
    default: 'Your code: :code',
    value: 'Code :code',
    placeholders: ['code'],
    required: ['code'],
};

describe('Admin → Notifications texts (docs/spec/09)', () => {
    it("shows the operator's words, else the code's", () => {
        expect(shownText(subject)).toBe('Confirmed: :when');
        expect(shownText(code)).toBe('Code :code');
    });

    it("sends null for a text back at the code's words, and previews only what changed", () => {
        const values = { [subject.key]: 'Confirmed: :when', [code.key]: 'Here is :code' };

        expect(textsPayload([subject, code], values)).toEqual({ [subject.key]: null, [code.key]: 'Here is :code' });
        expect(unsavedTexts([subject, code], values)).toEqual({ [code.key]: 'Here is :code' });
        expect(unsavedTexts([subject, code], { [subject.key]: 'Confirmed: :when', [code.key]: 'Code :code' })).toEqual(
            {},
        );
    });

    it('puts a placeholder at the cursor, with a space before it when needed', () => {
        expect(insertPlaceholder('Booked for', 'when')).toEqual({ value: 'Booked for :when', cursor: 16 });
        expect(insertPlaceholder('Booked  today', 'when', 7)).toEqual({ value: 'Booked :when today', cursor: 12 });
        expect(insertPlaceholder('', 'code')).toEqual({ value: ':code', cursor: 5 });
        expect(insertPlaceholder('at X ok', 'when', 3, 4)).toEqual({ value: 'at :when ok', cursor: 8 });
    });

    it('finds placeholders a text is not given and required ones it lost — a time is no placeholder', () => {
        expect(placeholderProblems('Your code', code)).toEqual({ unknown: [], missing: ['code'] });
        expect(placeholderProblems(':code for :practice at 10:30', code)).toEqual({
            unknown: ['practice'],
            missing: [],
        });
        expect(placeholderProblems('See https://example.com at 10:30, :code', code)).toEqual({
            unknown: [],
            missing: [],
        });
    });
});
