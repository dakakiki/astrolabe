import { describe, expect, it } from 'vitest';

import { describeEvent } from '../../resources/js/lib/timeline';

const event = (type, metadata = {}, extra = {}) => ({ type, metadata, subject: { type, id: 7 }, ...extra });

describe('describeEvent', () => {
    it('leads a consultation to its own page, worded by status', () => {
        const entry = describeEvent(
            event('consultation', { status: 'completed', title: 'Natal reading', topics: 'Career' }),
        );

        expect(entry.tone).toBe('consultation');
        expect(entry.title).toEqual(['timeline.consultation.completed', { title: 'Natal reading' }]);
        expect(entry.body).toBe('Career');
        expect(entry.to).toEqual({ name: 'consultations.show', params: { id: 7 } });
    });

    it('names an untitled consultation after its service', () => {
        const entry = describeEvent(
            event('consultation', { status: 'completed', title: null, service: 'Solar return' }),
        );

        expect(entry.title).toEqual(['timeline.consultation.completed', { title: 'Solar return' }]);
        expect(entry.titled).toBe(true);
        expect(describeEvent(event('consultation', { status: 'draft' })).titled).toBe(false);
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

    it('names a task and its completion, and leads both to the tasks tab', () => {
        const added = describeEvent(
            event('task', { title: 'Send the summary', due_date: '2026-10-09', priority: 'high' }),
        );

        expect(added).toMatchObject({
            tone: 'task',
            title: ['timeline.task', { title: 'Send the summary' }],
            task: { due_date: '2026-10-09', priority: 'high' },
            to: { tab: 'tasks' },
        });
        expect(describeEvent(event('task_completed', {}, { summary: 'Send the summary' })).title).toEqual([
            'timeline.taskCompleted',
            { title: 'Send the summary' },
        ]);
    });

    it('shows a payment on its day, with its amount, leading to what it paid for', () => {
        const paid = describeEvent(
            event(
                'payment',
                { kind: 'payment', amount: 5000, currency: 'EUR', paid_on: '2026-10-05', consultation_id: 4 },
                { summary: 'INV-7' },
            ),
        );

        expect(paid).toMatchObject({
            tone: 'payment',
            title: ['timeline.payment', {}],
            money: { amount: 5000, currency: 'EUR' },
            day: '2026-10-05',
            body: 'INV-7',
            to: { name: 'consultations.show', params: { id: 4 } },
        });
    });

    it('tells a deposit and a refund apart', () => {
        const deposit = describeEvent(event('payment', { kind: 'payment', appointment_id: 9, consultation_id: null }));
        expect(deposit.title).toEqual(['timeline.deposit', {}]);
        expect(deposit.to).toEqual({ name: 'calendar', query: { appointment: 9 } });

        const refund = describeEvent(event('payment', { kind: 'refund', appointment_id: 9, consultation_id: 4 }));
        expect(refund.title).toEqual(['timeline.refund', {}]);

        expect(describeEvent(event('payment', { kind: 'payment' })).to).toBeNull();
    });

    it('never fails on an entry type it does not know', () => {
        expect(describeEvent(event('invoice')).title).toEqual(['timeline.unknown', {}]);
    });
});
