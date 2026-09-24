import { describe, expect, it } from 'vitest';

import { describeEvent } from '../../resources/js/lib/timeline';

const event = (type, metadata = {}, extra = {}) => ({ type, metadata, subject: { type, id: 7 }, ...extra });

describe('describeEvent', () => {
    it('leads a consultation to its own page, worded by status', () => {
        const entry = describeEvent(event('consultation', { status: 'completed', title: 'Natal reading', topics: 'Career' }));

        expect(entry.tone).toBe('consultation');
        expect(entry.title).toEqual(['timeline.consultation.completed', { title: 'Natal reading' }]);
        expect(entry.body).toBe('Career');
        expect(entry.to).toEqual({ name: 'consultations.show', params: { id: 7 } });
    });

    it('marks private notes and falls back to a plain title', () => {
        const entry = describeEvent(event('note', { excerpt: 'Call in March' }, { visibility: 'private' }));

        expect(entry.title).toEqual(['timeline.note', {}]);
        expect(entry.body).toBe('Call in March');
        expect(entry.private).toBe(true);
        expect(entry.to).toEqual({ tab: 'notes' });
    });

    it('leads a file on a consultation to that consultation', () => {
        expect(describeEvent(event('file', { kind: 'file', name: 'a.pdf', consultation_id: 3 })).to).toEqual({
            name: 'consultations.show',
            params: { id: 3 },
        });
        expect(describeEvent(event('file', { kind: 'link', name: 'zoom.us' })).title).toEqual([
            'timeline.link',
            { name: 'zoom.us' },
        ]);
    });

    it('tells a first chart from a recalculation', () => {
        expect(describeEvent(event('chart_calculated', { recalculated: false })).title[0]).toBe(
            'timeline.chartCalculated',
        );
        expect(describeEvent(event('chart_calculated', { recalculated: true })).title[0]).toBe(
            'timeline.chartRecalculated',
        );
    });

    it('lists the fields a change touched', () => {
        expect(describeEvent(event('birth_details_updated', { added: false, fields: ['time'] }))).toMatchObject({
            title: ['timeline.birthUpdated', {}],
            fields: ['time'],
        });
        expect(describeEvent(event('client_updated', { fields: ['email', 'tags'] })).fields).toEqual(['email', 'tags']);
    });

    it('never fails on an entry type it does not know', () => {
        expect(describeEvent(event('payment')).title).toEqual(['timeline.unknown', {}]);
    });
});
