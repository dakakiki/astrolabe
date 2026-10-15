import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import {
    addMonths,
    APPOINTMENT_STATUSES,
    appointmentPatch,
    bookedInPortal,
    cancellation,
    conflictingIds,
    daysBetween,
    groupByDay,
    isAhead,
    isOpen,
    layoutDay,
    movesAppointment,
    offersCancelEmail,
    offersMoveEmail,
    requestsAgenda,
    shiftDate,
    slotStart,
    startOfWeek,
    todayIn,
    viewRange,
    visibleHours,
    wallClock,
    weekday,
} from '../../resources/js/lib/calendar';

const appointment = (id, starts_at, ends_at, extra = {}) => ({
    id,
    starts_at,
    ends_at,
    status: 'scheduled',
    assigned_user: { id: 1 },
    ...extra,
});

describe('date keys', () => {
    it('counts weeks from Monday', () => {
        expect(weekday('2026-10-05')).toBe(0);
        expect(weekday('2026-10-11')).toBe(6);
        expect(startOfWeek('2026-10-08')).toBe('2026-10-05');
        expect(startOfWeek('2026-11-01')).toBe('2026-10-26');
    });

    it('moves by months from the first of the month', () => {
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-01');
        expect(addMonths('2026-12-15', 1)).toBe('2027-01-01');
        expect(addMonths('2026-03-10', -3)).toBe('2025-12-01');
    });

    it('lists every day of a range across a clock change', () => {
        expect(daysBetween('2026-10-24', '2026-10-27')).toEqual([
            '2026-10-24',
            '2026-10-25',
            '2026-10-26',
            '2026-10-27',
        ]);
    });
});

describe('viewRange and shiftDate', () => {
    it('gives the days each view shows', () => {
        expect(viewRange('day', '2026-10-07')).toEqual({ from: '2026-10-07', to: '2026-10-07' });
        expect(viewRange('week', '2026-10-07')).toEqual({ from: '2026-10-05', to: '2026-10-11' });
        // October 2026 starts on a Thursday: six weeks from Monday 28 September.
        expect(viewRange('month', '2026-10-20')).toEqual({ from: '2026-09-28', to: '2026-11-08' });
        expect(viewRange('agenda', '2026-10-07')).toEqual({ from: '2026-10-07', to: '2026-11-05' });
    });

    it('steps by the view', () => {
        expect(shiftDate('day', '2026-10-07', 1)).toBe('2026-10-08');
        expect(shiftDate('week', '2026-10-07', -1)).toBe('2026-09-30');
        expect(shiftDate('month', '2026-10-31', 1)).toBe('2026-11-01');
        expect(shiftDate('agenda', '2026-10-07', 1)).toBe('2026-11-06');
    });
});

describe('wallClock', () => {
    it('places a UTC moment on the zone clock, with summer time', () => {
        expect(wallClock('2026-10-24T08:00:00Z', 'Europe/Belgrade')).toEqual({ date: '2026-10-24', minutes: 600 });
        expect(wallClock('2026-10-26T09:00:00Z', 'Europe/Belgrade')).toEqual({ date: '2026-10-26', minutes: 600 });
        expect(wallClock('2026-10-04T22:30:00Z', 'Europe/Belgrade')).toEqual({ date: '2026-10-05', minutes: 30 });
    });

    it('knows which day it is in a zone', () => {
        expect(todayIn('Asia/Tokyo', new Date('2026-10-04T20:00:00Z'))).toBe('2026-10-05');
    });
});

describe('layoutDay', () => {
    it('places appointments by minutes and puts overlapping ones side by side', () => {
        const items = layoutDay(
            [
                appointment(1, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z'),
                appointment(2, '2026-10-05T08:30:00Z', '2026-10-05T09:30:00Z'),
                appointment(3, '2026-10-05T12:00:00Z', '2026-10-05T12:45:00Z'),
            ],
            '2026-10-05',
            'Europe/Belgrade',
        );

        expect(items.map((item) => [item.appointment.id, item.start, item.end, item.column, item.columns])).toEqual([
            [1, 600, 660, 0, 2],
            [2, 630, 690, 1, 2],
            [3, 840, 885, 0, 1],
        ]);
    });

    it('reuses a column once it is free within a group', () => {
        const items = layoutDay(
            [
                appointment(1, '2026-10-05T08:00:00Z', '2026-10-05T11:00:00Z'),
                appointment(2, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z'),
                appointment(3, '2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'),
            ],
            '2026-10-05',
            'UTC',
        );

        expect(items.map((item) => [item.appointment.id, item.column, item.columns])).toEqual([
            [1, 0, 2],
            [2, 1, 2],
            [3, 1, 2],
        ]);
    });

    it('cuts an appointment at midnight and leaves out one that only ends there', () => {
        const late = appointment(1, '2026-10-05T21:30:00Z', '2026-10-05T23:00:00Z');

        expect(layoutDay([late], '2026-10-05', 'Europe/Belgrade')[0]).toMatchObject({ start: 1410, end: 1440 });
        expect(layoutDay([late], '2026-10-06', 'Europe/Belgrade')[0]).toMatchObject({ start: 0, end: 60 });
        expect(
            layoutDay(
                [appointment(2, '2026-10-05T21:00:00Z', '2026-10-05T22:00:00Z')],
                '2026-10-06',
                'Europe/Belgrade',
            ),
        ).toEqual([]);
    });
});

describe('conflictingIds', () => {
    it('marks overlaps of the same astrologer, never cancelled ones', () => {
        const ids = conflictingIds([
            appointment(1, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z'),
            appointment(2, '2026-10-05T08:30:00Z', '2026-10-05T09:30:00Z'),
            appointment(3, '2026-10-05T09:30:00Z', '2026-10-05T10:00:00Z'),
            appointment(4, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z', { status: 'cancelled' }),
            appointment(5, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z', { assigned_user: { id: 2 } }),
        ]);

        expect([...ids].sort()).toEqual([1, 2]);
    });

    it('counts a booking request as taken time (Phase 9b)', () => {
        const ids = conflictingIds([
            appointment(1, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z', { status: 'requested' }),
            appointment(2, '2026-10-05T08:30:00Z', '2026-10-05T09:30:00Z'),
            appointment(3, '2026-10-05T12:00:00Z', '2026-10-05T13:00:00Z', { status: 'requested' }),
            appointment(4, '2026-10-05T12:30:00Z', '2026-10-05T13:00:00Z', { status: 'cancelled' }),
        ]);

        expect([...ids].sort()).toEqual([1, 2]);
    });
});

describe('statuses and the portal (Phase 9b)', () => {
    it('lists every status, a request first', () => {
        expect(APPOINTMENT_STATUSES).toEqual(['requested', 'scheduled', 'completed', 'cancelled', 'no_show']);
    });

    it('keeps scheduled appointments and requests open', () => {
        expect(isOpen({ status: 'scheduled' })).toBe(true);
        expect(isOpen({ status: 'requested' })).toBe(true);
        ['completed', 'cancelled', 'no_show'].forEach((status) => expect(isOpen({ status })).toBe(false));
        expect(isOpen(null)).toBe(false);
    });

    it('knows what the client booked in the portal', () => {
        expect(bookedInPortal({ booking_source: 'portal' })).toBe(true);
        expect(bookedInPortal({ booking_source: 'manual' })).toBe(false);
        expect(bookedInPortal(null)).toBe(false);
    });
});

describe('cancellation', () => {
    const cancelled = (extra) => ({
        status: 'cancelled',
        cancelled_at: '2026-10-05T10:00:00Z',
        cancellation_reason: 'Ill',
        cancelled_by: 'practice',
        declined_at: null,
        ...extra,
    });

    it('is nothing while the appointment stands', () => {
        expect(cancellation({ status: 'scheduled' })).toBeNull();
        expect(cancellation({ status: 'requested' })).toBeNull();
        expect(cancellation(null)).toBeNull();
    });

    it('tells a decline, a cancellation by the client and one by the practice apart', () => {
        expect(cancellation(cancelled())).toEqual({ by: 'practice', at: '2026-10-05T10:00:00Z', reason: 'Ill' });
        expect(cancellation(cancelled({ cancelled_by: 'client', cancellation_reason: null }))).toEqual({
            by: 'client',
            at: '2026-10-05T10:00:00Z',
            reason: null,
        });
        // A declined request was cancelled by the practice, but reads as declined.
        expect(
            cancellation(cancelled({ declined_at: '2026-10-05T10:00:00Z', cancellation_reason: 'Away that week' })),
        ).toEqual({ by: 'declined', at: '2026-10-05T10:00:00Z', reason: 'Away that week' });
        // From before Phase 9b: no `cancelled_by`.
        expect(cancellation({ status: 'cancelled', cancellation_reason: '' })).toEqual({
            by: 'practice',
            at: null,
            reason: null,
        });
    });
});

describe('movesAppointment', () => {
    const booked = { starts_at_local: '2026-10-20T10:00', timezone: 'Europe/Belgrade', duration_minutes: 60 };
    const form = (extra = {}) => ({
        starts_at: '2026-10-20T10:00',
        timezone: 'Europe/Belgrade',
        duration_minutes: 60,
        ...extra,
    });

    it('sees a new start, zone or length', () => {
        expect(movesAppointment(booked, form())).toBe(false);
        expect(movesAppointment(booked, form({ starts_at: '2026-10-20T11:00' }))).toBe(true);
        expect(movesAppointment(booked, form({ starts_at: '2026-10-21T10:00' }))).toBe(true);
        expect(movesAppointment(booked, form({ timezone: 'Europe/London' }))).toBe(true);
        expect(movesAppointment(booked, form({ duration_minutes: 90 }))).toBe(true);
    });

    it('keeps the length when the field is left empty, and never moves a new appointment', () => {
        expect(movesAppointment(booked, form({ duration_minutes: '' }))).toBe(false);
        expect(movesAppointment(booked, form({ duration_minutes: null }))).toBe(false);
        expect(movesAppointment(null, form())).toBe(false);
    });
});

describe('appointmentPatch', () => {
    // As the form was opened: the client may have moved it in the portal since.
    const opened = { starts_at_local: '2026-10-20T10:00', timezone: 'Europe/Belgrade', duration_minutes: 60 };
    const values = (extra = {}) => ({
        service_id: 3,
        starts_at: '2026-10-20T10:00',
        timezone: 'Europe/Belgrade',
        duration_minutes: 60,
        location_type: 'online',
        location_details: 'https://zoom.example/abc',
        notes: null,
        reminder_minutes: 60,
        notify_client: false,
        ...extra,
    });

    it('leaves the time out of an edit that does not move the appointment', () => {
        expect(appointmentPatch(opened, values())).toEqual({
            service_id: 3,
            location_type: 'online',
            location_details: 'https://zoom.example/abc',
            notes: null,
            reminder_minutes: 60,
            notify_client: false,
        });
        // An emptied length keeps the one it has: still not a move.
        expect(appointmentPatch(opened, values({ duration_minutes: null }))).not.toHaveProperty('duration_minutes');
    });

    it('sends the whole time when the edit moves it', () => {
        for (const change of [
            { starts_at: '2026-10-21T10:00' },
            { timezone: 'Europe/London' },
            { duration_minutes: 90 },
        ]) {
            const patch = appointmentPatch(opened, values(change));

            expect(patch).toEqual(values(change));
            expect(patch).toMatchObject({
                starts_at: expect.any(String),
                timezone: expect.any(String),
            });
            expect(patch).toHaveProperty('duration_minutes');
        }
    });

    it('does not change the values it was given', () => {
        const given = values();
        appointmentPatch(opened, given);

        expect(given).toEqual(values());
    });
});

describe('emailing the client about a change (Phase 9b)', () => {
    const now = new Date('2026-10-15T08:00:00Z'); // 10:00 in Belgrade
    const open = {
        status: 'scheduled',
        client_portal: true,
        starts_at: '2026-10-20T08:00:00Z',
        starts_at_local: '2026-10-20T10:00',
        timezone: 'Europe/Belgrade',
        duration_minutes: 60,
    };
    const moved = (extra = {}) => ({
        starts_at: '2026-10-21T10:00',
        timezone: 'Europe/Belgrade',
        duration_minutes: 60,
        ...extra,
    });

    it('reads a wall-clock time in its own zone against now', () => {
        expect(isAhead('2026-10-15T10:01', 'Europe/Belgrade', now)).toBe(true);
        expect(isAhead('2026-10-15T10:00', 'Europe/Belgrade', now)).toBe(false);
        expect(isAhead('2026-10-15T09:30', 'Europe/Belgrade', now)).toBe(false);
        // Still the past in Belgrade, but ahead in New York (04:00 there).
        expect(isAhead('2026-10-15T09:30', 'America/New_York', now)).toBe(true);
        expect(isAhead('2026-10-15T09:30:00', 'America/New_York', now)).toBe(true);
        expect(isAhead('', 'Europe/Belgrade', now)).toBe(false);
        expect(isAhead(null, 'Europe/Belgrade', now)).toBe(false);
        expect(isAhead('2026-10-20T10:00', 'Not/AZone', now)).toBe(false);
    });

    it('offers to email a move of an open appointment to a time still ahead', () => {
        expect(offersMoveEmail(open, moved(), now)).toBe(true);
        expect(offersMoveEmail({ ...open, status: 'requested' }, moved(), now)).toBe(true);
    });

    it('does not offer it when the server would send nothing', () => {
        // Not a move.
        expect(offersMoveEmail(open, moved({ starts_at: '2026-10-20T10:00' }), now)).toBe(false);
        // Held, cancelled or missed: the client is not told of a corrected time.
        ['completed', 'cancelled', 'no_show'].forEach((status) =>
            expect(offersMoveEmail({ ...open, status }, moved(), now)).toBe(false),
        );
        // The new time has already passed, in the zone the form gives it in.
        expect(offersMoveEmail(open, moved({ starts_at: '2026-10-14T10:00' }), now)).toBe(false);
        expect(offersMoveEmail(open, moved({ starts_at: '2026-10-15T09:30' }), now)).toBe(false);
        expect(offersMoveEmail(open, moved({ starts_at: '2026-10-15T09:30', timezone: 'America/New_York' }), now)).toBe(
            true,
        );
        // The client cannot use the portal, or it is not known.
        expect(offersMoveEmail({ ...open, client_portal: false }, moved(), now)).toBe(false);
        expect(offersMoveEmail({ ...open, client_portal: undefined }, moved(), now)).toBe(false);
        expect(offersMoveEmail(null, moved(), now)).toBe(false);
    });

    it('offers to email a cancellation only before the appointment starts', () => {
        expect(offersCancelEmail(open, now)).toBe(true);
        expect(offersCancelEmail({ ...open, starts_at: '2026-10-15T07:00:00Z' }, now)).toBe(false);
        expect(offersCancelEmail({ ...open, starts_at: '2026-10-15T08:00:00Z' }, now)).toBe(false);
        expect(offersCancelEmail({ ...open, client_portal: false }, now)).toBe(false);
        expect(offersCancelEmail(null, now)).toBe(false);
    });
});

describe('requestsAgenda', () => {
    it('opens the agenda of requests on the day of the last one listed', () => {
        const listed = [
            { id: 1, starts_at: '2026-10-16T08:00:00Z' },
            { id: 2, starts_at: '2026-11-03T22:30:00Z' },
        ];

        // 23:30 on 3 November in Belgrade, already the 4th in Tokyo.
        expect(requestsAgenda(listed, 'Europe/Belgrade')).toEqual({
            view: 'agenda',
            status: 'requested',
            date: '2026-11-03',
        });
        expect(requestsAgenda(listed, 'Asia/Tokyo').date).toBe('2026-11-04');
        // The agenda from that day reaches the requests left out of the list.
        expect(viewRange('agenda', requestsAgenda(listed, 'UTC').date).from).toBe('2026-11-03');
    });

    it('opens from today when nothing is listed', () => {
        expect(requestsAgenda([], 'UTC')).toEqual({ view: 'agenda', status: 'requested' });
    });
});

describe('the appointment panel in the calendar', () => {
    it('is a fresh component for each appointment, so nothing typed for one reaches another', () => {
        const page = readFileSync(
            new URL('../../resources/js/pages/calendar/CalendarPage.vue', import.meta.url),
            'utf8',
        );
        const panel = /<AppointmentDetail\b[^>]*>/.exec(page)?.[0] ?? '';

        expect(panel).toMatch(/:key="selectedId"/);
    });
});

describe('groupByDay', () => {
    it('groups by the local start day, in order', () => {
        const groups = groupByDay(
            [
                appointment(2, '2026-10-06T08:00:00Z', '2026-10-06T09:00:00Z'),
                appointment(1, '2026-10-04T22:30:00Z', '2026-10-04T23:30:00Z'),
                appointment(3, '2026-10-05T12:00:00Z', '2026-10-05T13:00:00Z'),
            ],
            'Europe/Belgrade',
        );

        expect(groups.map((group) => [group.date, group.appointments.map((item) => item.id)])).toEqual([
            ['2026-10-05', [1, 3]],
            ['2026-10-06', [2]],
        ]);
    });
});

describe('visibleHours and slotStart', () => {
    it('widens the working day to fit early and late appointments', () => {
        expect(visibleHours([])).toEqual([8, 20]);
        expect(
            visibleHours([
                { start: 390, end: 450 },
                { start: 1260, end: 1330 },
            ]),
        ).toEqual([6, 23]);
    });

    it('snaps a click to the half hour', () => {
        expect(slotStart('2026-10-05', 905)).toBe('2026-10-05T15:00');
        expect(slotStart('2026-10-05', 935)).toBe('2026-10-05T15:30');
        expect(slotStart('2026-10-05', 1439)).toBe('2026-10-05T23:30');
    });
});
