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
        // The client's own change in the portal (Phase 9a).
        expect(describeEvent(event('client_updated', { fields: ['phone'], source: 'portal' })).title).toEqual([
            'timeline.profileUpdatedByClient',
            {},
        ]);
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

    it('words an appointment by its status and leads it to the calendar', () => {
        const entry = describeEvent(event('appointment', { status: 'completed', service: 'Natal reading' }));

        expect(entry).toMatchObject({
            tone: 'appointment',
            title: ['timeline.appointment.completed', { title: 'Natal reading' }],
            titled: true,
            detail: null,
            cancelled: false,
            to: { name: 'calendar', query: { appointment: 7 } },
        });
        expect(describeEvent(event('appointment', { status: 'cancelled' })).cancelled).toBe(true);
    });

    it('says what the client booked in the portal (Phase 9b)', () => {
        const portal = (status) =>
            describeEvent(event('appointment', { status, service: 'Natal reading', booking_source: 'portal' }));

        // Waiting for the astrologer, then booked.
        expect(portal('requested')).toMatchObject({
            title: ['timeline.appointment.requested', { title: 'Natal reading' }],
            detail: null,
        });
        expect(portal('scheduled')).toMatchObject({
            title: ['timeline.bookedInPortal', { title: 'Natal reading' }],
            titled: true,
            detail: null,
        });
        // Held or ended: the status leads, the source follows.
        expect(portal('completed')).toMatchObject({
            title: ['timeline.appointment.completed', { title: 'Natal reading' }],
            detail: ['timeline.bookedInPortal', {}],
        });
        expect(portal('cancelled').cancelled).toBe(true);

        // Entered by the practice: no source line.
        expect(describeEvent(event('appointment', { status: 'scheduled', booking_source: 'manual' }))).toMatchObject({
            title: ['timeline.appointment.scheduled', { title: '' }],
            detail: null,
        });
    });

    it('tells a move by the client in the portal from one by the practice', () => {
        const meta = { from: '2026-10-20T08:00:00Z', to: '2026-10-21T09:00:00Z' };

        expect(describeEvent(event('appointment_rescheduled', meta))).toMatchObject({
            title: ['timeline.appointmentMoved', {}],
            moved: { from: '2026-10-20T08:00:00Z', to: '2026-10-21T09:00:00Z' },
            to: { name: 'calendar', query: { appointment: 7 } },
        });
        expect(describeEvent(event('appointment_rescheduled', { ...meta, source: 'portal' })).title).toEqual([
            'timeline.appointmentMovedByClient',
            {},
        ]);
    });

    it('tells who cancelled, and shows a declined request’s message as sent to the client', () => {
        const startsAt = '2026-10-20T08:00:00Z';

        expect(
            describeEvent(event('appointment_cancelled', { starts_at: startsAt, reason: 'Ill, sorry' })),
        ).toMatchObject({
            title: ['timeline.appointmentCancelled', {}],
            body: 'Ill, sorry',
            detail: null,
            wasAt: startsAt,
        });

        // The client cancelled in the portal: the body is their own message, if any.
        expect(
            describeEvent(
                event('appointment_cancelled', { starts_at: startsAt, reason: 'Travelling', source: 'portal' }),
            ),
        ).toMatchObject({ title: ['timeline.appointmentCancelledByClient', {}], body: 'Travelling', detail: null });
        expect(
            describeEvent(event('appointment_cancelled', { starts_at: startsAt, source: 'portal' })).body,
        ).toBeNull();

        // The astrologer declined a request: the reason went to the client.
        expect(
            describeEvent(
                event('appointment_cancelled', { starts_at: startsAt, reason: 'Away that week', declined: true }),
            ),
        ).toMatchObject({
            title: ['timeline.requestDeclined', {}],
            body: 'Away that week',
            detail: ['timeline.declineSent', {}],
            wasAt: startsAt,
        });
        expect(describeEvent(event('appointment_cancelled', { starts_at: startsAt, declined: true }))).toMatchObject({
            title: ['timeline.requestDeclined', {}],
            body: null,
            detail: null,
        });
    });

    it('notes a confirmed booking request with the time it is for', () => {
        expect(
            describeEvent(
                event('appointment_confirmed', { starts_at: '2026-10-20T08:00:00Z', timezone: 'Europe/Belgrade' }),
            ),
        ).toEqual({
            tone: 'appointment',
            title: ['timeline.appointmentConfirmed', {}],
            at: '2026-10-20T08:00:00Z',
            to: { name: 'calendar', query: { appointment: 7 } },
        });
    });

    it('never fails on an entry type it does not know', () => {
        expect(describeEvent(event('invoice')).title).toEqual(['timeline.unknown', {}]);
    });
});
