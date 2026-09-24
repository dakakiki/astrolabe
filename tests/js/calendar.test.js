import { describe, expect, it } from 'vitest';

import {
    addMonths,
    conflictingIds,
    daysBetween,
    groupByDay,
    layoutDay,
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
        expect(layoutDay([appointment(2, '2026-10-05T21:00:00Z', '2026-10-05T22:00:00Z')], '2026-10-06', 'Europe/Belgrade')).toEqual([]);
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
        expect(visibleHours([{ start: 390, end: 450 }, { start: 1260, end: 1330 }])).toEqual([6, 23]);
    });

    it('snaps a click to the half hour', () => {
        expect(slotStart('2026-10-05', 905)).toBe('2026-10-05T15:00');
        expect(slotStart('2026-10-05', 935)).toBe('2026-10-05T15:30');
        expect(slotStart('2026-10-05', 1439)).toBe('2026-10-05T23:30');
    });
});
