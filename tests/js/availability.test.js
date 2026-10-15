import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import {
    addStretch,
    BUFFER_CHOICES,
    canAddStretch,
    CHANGE_UNTIL_CHOICES,
    choicesWith,
    copyMondayToWeekdays,
    DEFAULT_STRETCH,
    durationParts,
    endMinutesOf,
    exceptionDays,
    exceptionForm,
    exceptionPayload,
    exceptionPeriod,
    hasWorkingTime,
    inputTime,
    MAX_DAYS_CHOICES,
    MAX_STRETCHES,
    MIN_NOTICE_CHOICES,
    minutesOf,
    nextStretch,
    POLICY_DEFAULTS,
    policyForm,
    policyPayload,
    removeStretch,
    SLOT_STEP_CHOICES,
    switchDay,
    timeOf,
    upcomingExceptions,
    validateException,
    validateWeek,
    weekErrors,
    weekForm,
    weekPayload,
    WEEKDAYS,
    bookingState,
    weekdayName,
    withWholeDays,
} from '@/lib/availability';
import routes from '@/router/routes';

const rule = (weekday, start_time, end_time) => ({ weekday, start_time, end_time });
const day = (week, weekday) => week.find((entry) => entry.weekday === weekday);

describe('times of day', () => {
    it('reads and writes wall-clock times in minutes', () => {
        expect(minutesOf('09:30')).toBe(570);
        expect(minutesOf('00:00')).toBe(0);
        expect(minutesOf('24:00')).toBe(1440);
        expect(minutesOf('09:30:00')).toBe(570);
        expect(minutesOf('')).toBeNull();
        expect(minutesOf(null)).toBeNull();
        expect(minutesOf('24:30')).toBeNull();
        expect(minutesOf('09:75')).toBeNull();
        expect(timeOf(570)).toBe('09:30');
        expect(timeOf(1440)).toBe('24:00');
    });

    it('takes midnight as the end of the day, which a time input shows as 00:00', () => {
        expect(endMinutesOf('00:00')).toBe(1440);
        expect(endMinutesOf('17:00')).toBe(1020);
        expect(endMinutesOf('')).toBeNull();
        expect(inputTime('24:00')).toBe('00:00');
        expect(inputTime('09:00:00')).toBe('09:00');
        expect(inputTime(null)).toBe('');
    });
});

describe('the working week', () => {
    it('names the days in the viewer’s language, Monday first', () => {
        expect(WEEKDAYS.map((weekday) => weekdayName(weekday, 'en'))).toEqual([
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday',
            'Sunday',
        ]);
        expect(weekdayName(1, 'de')).toBe('Montag');
        expect(weekdayName(7, 'en', 'short')).toBe('Sun');
    });

    it('builds Monday to Sunday from the rules, each day’s stretches in order', () => {
        const week = weekForm([
            rule(1, '15:00', '19:00'),
            rule(1, '09:00', '13:00'),
            rule(3, '10:00', '24:00'),
            rule('7', '12:00:00', '14:00:00'),
        ]);

        expect(week.map((entry) => entry.weekday)).toEqual(WEEKDAYS);
        expect(day(week, 1)).toEqual({
            weekday: 1,
            on: true,
            stretches: [
                { start: '09:00', end: '13:00' },
                { start: '15:00', end: '19:00' },
            ],
        });
        expect(day(week, 2)).toEqual({ weekday: 2, on: false, stretches: [] });
        expect(day(week, 3).stretches).toEqual([{ start: '10:00', end: '00:00' }]);
        expect(day(week, 7).stretches).toEqual([{ start: '12:00', end: '14:00' }]);
        expect(hasWorkingTime(week)).toBe(true);
        expect(hasWorkingTime(weekForm([]))).toBe(false);
    });

    it('gives a day switched on the usual hours, and keeps its hours while it is off', () => {
        const off = { weekday: 2, on: false, stretches: [] };
        const on = switchDay(off, true);

        expect(on).toEqual({ weekday: 2, on: true, stretches: [DEFAULT_STRETCH] });
        expect(on.stretches[0]).not.toBe(DEFAULT_STRETCH);

        const again = switchDay(
            switchDay({ weekday: 2, on: true, stretches: [{ start: '08:00', end: '12:00' }] }, false),
            true,
        );
        expect(again.stretches).toEqual([{ start: '08:00', end: '12:00' }]);
    });

    it('suggests the next stretch an hour after the last one, up to midnight', () => {
        expect(nextStretch([])).toEqual(DEFAULT_STRETCH);
        expect(nextStretch([{ start: '09:00', end: '13:00' }])).toEqual({ start: '14:00', end: '18:00' });
        expect(nextStretch([{ start: '18:00', end: '22:00' }])).toEqual({ start: '23:00', end: '00:00' });
        expect(nextStretch([{ start: '20:00', end: '23:30' }])).toEqual({ start: '23:30', end: '00:00' });
        expect(nextStretch([{ start: '09:00', end: '' }])).toBeNull();
    });

    it('suggests free time earlier in the day once the day runs to midnight', () => {
        // Evenings to midnight: up to four hours, ending an hour before them.
        expect(nextStretch([{ start: '18:00', end: '00:00' }])).toEqual({ start: '13:00', end: '17:00' });
        expect(nextStretch([{ start: '20:00', end: '00:00' }])).toEqual({ start: '15:00', end: '19:00' });
        expect(nextStretch([{ start: '09:00', end: '00:00' }])).toEqual({ start: '04:00', end: '08:00' });
        // The first free time of the day, whatever order the stretches are in.
        expect(
            nextStretch([
                { start: '18:00', end: '00:00' },
                { start: '10:00', end: '12:00' },
            ]),
        ).toEqual({ start: '05:00', end: '09:00' });
        // Between two stretches.
        expect(
            nextStretch([
                { start: '12:00', end: '00:00' },
                { start: '00:00', end: '09:00' },
            ]),
        ).toEqual({ start: '09:00', end: '11:00' });
        // Less than two hours free: all of it.
        expect(
            nextStretch([
                { start: '00:00', end: '12:00' },
                { start: '13:30', end: '00:00' },
            ]),
        ).toEqual({ start: '12:00', end: '13:30' });
        expect(
            nextStretch([
                { start: '00:00', end: '12:00' },
                { start: '12:15', end: '00:00' },
            ]),
        ).toEqual({ start: '12:00', end: '12:15' });
        // An overlap still being fixed covers the time both take.
        expect(
            nextStretch([
                { start: '00:00', end: '10:00' },
                { start: '08:00', end: '12:00' },
                { start: '15:00', end: '00:00' },
            ]),
        ).toEqual({ start: '12:00', end: '14:00' });
    });

    it('suggests nothing when no free time of 15 minutes is left', () => {
        expect(nextStretch([{ start: '00:00', end: '00:00' }])).toBeNull();
        expect(
            nextStretch([
                { start: '00:00', end: '12:00' },
                { start: '12:00', end: '00:00' },
            ]),
        ).toBeNull();
        expect(
            nextStretch([
                { start: '00:10', end: '12:00' },
                { start: '12:10', end: '00:00' },
            ]),
        ).toBeNull();
    });

    it('adds and removes stretches, and a day without any is off', () => {
        let monday = { weekday: 1, on: true, stretches: [{ start: '09:00', end: '13:00' }] };
        monday = addStretch(monday);
        expect(monday.stretches).toEqual([
            { start: '09:00', end: '13:00' },
            { start: '14:00', end: '18:00' },
        ]);

        monday = removeStretch(monday, 0);
        expect(monday).toEqual({ weekday: 1, on: true, stretches: [{ start: '14:00', end: '18:00' }] });
        expect(removeStretch(monday, 0)).toEqual({ weekday: 1, on: false, stretches: [] });
    });

    it('stops at the limit of stretches a day', () => {
        const full = {
            weekday: 1,
            on: true,
            stretches: Array.from({ length: MAX_STRETCHES }, (_, hour) => ({
                start: timeOf(hour * 60),
                end: timeOf(hour * 60 + 30),
            })),
        };

        expect(canAddStretch(full)).toBe(false);
        expect(addStretch(full)).toBe(full);
        expect(canAddStretch({ weekday: 1, on: true, stretches: [{ start: '00:00', end: '00:00' }] })).toBe(false);
    });

    it('adds hours before a stretch that runs to midnight', () => {
        const friday = { weekday: 5, on: true, stretches: [{ start: '18:00', end: '00:00' }] };

        expect(canAddStretch(friday)).toBe(true);
        expect(addStretch(friday).stretches).toEqual([
            { start: '18:00', end: '00:00' },
            { start: '13:00', end: '17:00' },
        ]);
        expect(validateWeek([addStretch(friday)])).toEqual({});
    });

    it('copies Monday to Tuesday–Friday and leaves the weekend alone', () => {
        const week = weekForm([rule(1, '09:00', '13:00'), rule(1, '14:00', '18:00'), rule(6, '10:00', '12:00')]);
        const copied = copyMondayToWeekdays(week);

        for (const weekday of [2, 3, 4, 5]) {
            expect(day(copied, weekday)).toEqual({ ...day(week, 1), weekday });
            expect(day(copied, weekday).stretches[0]).not.toBe(day(week, 1).stretches[0]);
        }
        expect(day(copied, 6)).toBe(day(week, 6));
        expect(day(copied, 7)).toBe(day(week, 7));

        const mondayOff = copyMondayToWeekdays(weekForm([rule(2, '09:00', '17:00')]));
        expect(day(mondayOff, 2).on).toBe(false);
    });

    it('finds missing times, ends before starts and overlaps; touching stretches are fine', () => {
        const week = weekForm([]);
        week[0] = {
            weekday: 1,
            on: true,
            stretches: [
                { start: '09:00', end: '13:00' },
                { start: '13:00', end: '17:00' },
            ],
        };
        week[1] = { weekday: 2, on: true, stretches: [{ start: '17:00', end: '09:00' }] };
        week[2] = {
            weekday: 3,
            on: true,
            stretches: [
                { start: '09:00', end: '12:00' },
                { start: '11:00', end: '14:00' },
            ],
        };
        week[3] = { weekday: 4, on: true, stretches: [{ start: '', end: '12:00' }] };
        week[4] = { weekday: 5, on: true, stretches: [{ start: '20:00', end: '00:00' }] };
        week[5] = { weekday: 6, on: false, stretches: [{ start: '17:00', end: '09:00' }] };

        expect(validateWeek(week)).toEqual({
            '2.0': 'endBeforeStart',
            '3.0': 'overlap',
            3.1: 'overlap',
            '4.0': 'required',
        });
    });

    it('flags a day over the limit', () => {
        const week = weekForm([]);
        week[0] = {
            weekday: 1,
            on: true,
            stretches: Array.from({ length: MAX_STRETCHES + 1 }, (_, hour) => ({
                start: timeOf(hour * 60),
                end: timeOf(hour * 60 + 30),
            })),
        };

        expect(validateWeek(week)).toEqual({ 1: 'tooMany' });
    });

    it('sends the zone and every working stretch, with midnight as 24:00', () => {
        const week = weekForm([rule(1, '09:00', '13:00'), rule(1, '14:00', '24:00'), rule(3, '10:00', '12:00')]);
        week[4] = { weekday: 5, on: false, stretches: [{ start: '09:00', end: '17:00' }] };

        expect(weekPayload(week, 'Europe/Belgrade')).toEqual({
            timezone: 'Europe/Belgrade',
            rules: [
                { weekday: 1, start_time: '09:00', end_time: '13:00' },
                { weekday: 1, start_time: '14:00', end_time: '24:00' },
                { weekday: 3, start_time: '10:00', end_time: '12:00' },
            ],
        });
        expect(weekPayload(weekForm([]), 'UTC')).toEqual({ timezone: 'UTC', rules: [] });
    });

    it('puts the server’s errors back on the row they belong to', () => {
        const week = weekForm([rule(1, '09:00', '13:00'), rule(1, '14:00', '18:00'), rule(3, '10:00', '12:00')]);
        week[1] = { weekday: 2, on: false, stretches: [{ start: '09:00', end: '17:00' }] };

        expect(
            weekErrors(
                {
                    'rules.1.end_time': 'The end must be after the start.',
                    'rules.1.start_time': 'Another message.',
                    'rules.2': 'Overlaps.',
                    'rules.9.end_time': 'Nowhere to show it.',
                    rules: 'Too many.',
                    timezone: 'Not a zone.',
                },
                week,
            ),
        ).toEqual({
            stretches: { 1.1: 'The end must be after the start.', '3.0': 'Overlaps.' },
            other: { 'rules.9.end_time': 'Nowhere to show it.', rules: 'Too many.', timezone: 'Not a zone.' },
        });
        expect(weekErrors(null, week)).toEqual({ stretches: {}, other: {} });
    });
});

describe('time off and extra time', () => {
    const now = new Date('2026-10-15T22:30:00Z');

    it('starts as time off for today in the week’s zone', () => {
        // Already the 16th in Belgrade, still the 15th in New York.
        expect(exceptionForm('Europe/Belgrade', now)).toEqual({
            kind: 'unavailable',
            all_day: true,
            first_day: '2026-10-16',
            last_day: '2026-10-16',
            starts_local: '2026-10-16T09:00',
            ends_local: '2026-10-16T17:00',
            timezone: 'Europe/Belgrade',
            note: '',
        });
        expect(exceptionForm('America/New_York', now).first_day).toBe('2026-10-15');
    });

    it('carries the dates across when switching between whole days and times', () => {
        const days = { ...exceptionForm('UTC', now), first_day: '2026-12-24', last_day: '2026-12-26' };
        const times = withWholeDays(days, false);

        expect(times.all_day).toBe(false);
        expect(times.starts_local).toBe('2026-12-24T09:00');
        expect(times.ends_local).toBe('2026-12-26T17:00');
        expect(withWholeDays(times, false)).toBe(times);

        const edited = { ...times, starts_local: '2026-12-27T14:15', ends_local: '2026-12-27T18:45' };
        const back = withWholeDays(edited, true);
        expect(back).toMatchObject({ all_day: true, first_day: '2026-12-27', last_day: '2026-12-27' });
        expect(withWholeDays(back, false)).toMatchObject({
            starts_local: '2026-12-27T14:15',
            ends_local: '2026-12-27T18:45',
        });

        expect(
            withWholeDays({ ...times, starts_local: '2026-12-27T14:15', ends_local: '2026-12-20T18:45' }, true),
        ).toMatchObject({ first_day: '2026-12-27', last_day: '2026-12-27' });
    });

    it('checks the order and length of whole days', () => {
        const form = { ...exceptionForm('UTC', now), first_day: '2026-10-20', last_day: '2026-10-20' };

        expect(validateException(form, now)).toEqual({});
        expect(validateException({ ...form, last_day: '2026-10-19' }, now)).toEqual({ ends_at: 'lastBeforeFirst' });
        expect(validateException({ ...form, last_day: '2027-10-20' }, now)).toEqual({});
        expect(validateException({ ...form, last_day: '2027-10-21' }, now)).toEqual({ ends_at: 'tooLong' });
        expect(validateException({ ...form, first_day: '', last_day: '' }, now)).toEqual({
            starts_at: 'required',
            ends_at: 'required',
        });
    });

    it('checks the order and length of times', () => {
        const form = {
            ...exceptionForm('UTC', now),
            all_day: false,
            starts_local: '2026-10-20T09:00',
            ends_local: '2026-10-20T13:00',
        };

        expect(validateException(form, now)).toEqual({});
        expect(validateException({ ...form, ends_local: '2026-10-20T09:00' }, now)).toEqual({
            ends_at: 'endBeforeStart',
        });
        expect(validateException({ ...form, ends_local: '2027-10-21T09:00' }, now)).toEqual({});
        expect(validateException({ ...form, ends_local: '2027-10-21T09:15' }, now)).toEqual({ ends_at: 'tooLong' });
        expect(validateException({ ...form, starts_local: '' }, now)).toEqual({ starts_at: 'required' });
    });

    it('refuses times that have already ended, on the form’s own clock', () => {
        // 22:30 UTC on the 15th: 00:30 on the 16th in Belgrade, 18:30 on the 15th in New York.
        const today = (timezone, starts_local, ends_local) => ({
            ...exceptionForm(timezone, now),
            all_day: false,
            starts_local,
            ends_local,
            timezone,
        });

        // The form's default times for today, left in the evening: already over.
        expect(validateException(today('America/New_York', '2026-10-15T09:00', '2026-10-15T17:00'), now)).toEqual({
            ends_at: 'ended',
        });
        // Ending exactly now is over too; a minute later is not.
        expect(validateException(today('America/New_York', '2026-10-15T09:00', '2026-10-15T18:30'), now)).toEqual({
            ends_at: 'ended',
        });
        expect(validateException(today('America/New_York', '2026-10-15T09:00', '2026-10-15T18:31'), now)).toEqual({});
        // Started already but still running: fine.
        expect(validateException(today('America/New_York', '2026-10-15T09:00', '2026-10-15T21:00'), now)).toEqual({});
        // Already 07:30 on the 16th in Tokyo: that day's hours are still ahead.
        expect(validateException(today('Asia/Tokyo', '2026-10-16T09:00', '2026-10-16T17:00'), now)).toEqual({});
        expect(validateException(today('Europe/Belgrade', '2026-10-15T20:00', '2026-10-16T00:15'), now)).toEqual({
            ends_at: 'ended',
        });
        expect(validateException(today('Europe/Belgrade', '2026-10-15T20:00', '2026-10-16T00:45'), now)).toEqual({});
    });

    it('refuses whole days that are over, the last one counted to its midnight', () => {
        const days = (timezone, first_day, last_day) => ({
            ...exceptionForm(timezone, now),
            first_day,
            last_day,
            timezone,
        });

        // The 15th is over in Belgrade (00:30 on the 16th), still running in New York.
        expect(validateException(days('Europe/Belgrade', '2026-10-14', '2026-10-15'), now)).toEqual({
            ends_at: 'ended',
        });
        expect(validateException(days('America/New_York', '2026-10-14', '2026-10-15'), now)).toEqual({});
        // Today is never over.
        expect(validateException(days('Europe/Belgrade', '2026-10-16', '2026-10-16'), now)).toEqual({});
        // The order is checked first.
        expect(validateException(days('Europe/Belgrade', '2026-10-14', '2026-10-13'), now)).toEqual({
            ends_at: 'lastBeforeFirst',
        });
    });

    it('leaves the end to the server when the zone is unknown here', () => {
        const form = {
            ...exceptionForm('UTC', now),
            all_day: false,
            starts_local: '2020-01-01T09:00',
            ends_local: '2020-01-01T10:00',
            timezone: 'Not/AZone',
        };

        expect(validateException(form, now)).toEqual({});
    });

    it('sends whole days as dates and times as wall-clock values in the chosen zone', () => {
        const form = {
            ...exceptionForm('Europe/Belgrade', now),
            first_day: '2026-12-24',
            last_day: '2026-12-26',
            note: '  Holidays ',
        };

        expect(exceptionPayload(form)).toEqual({
            kind: 'unavailable',
            all_day: true,
            starts_at: '2026-12-24',
            ends_at: '2026-12-26',
            timezone: 'Europe/Belgrade',
            note: 'Holidays',
        });
        expect(
            exceptionPayload({
                ...form,
                kind: 'available',
                all_day: false,
                starts_local: '2026-10-24T09:00',
                ends_local: '2026-10-24T12:00',
                timezone: 'Asia/Tokyo',
                note: ' ',
            }),
        ).toEqual({
            kind: 'available',
            all_day: false,
            starts_at: '2026-10-24T09:00',
            ends_at: '2026-10-24T12:00',
            timezone: 'Asia/Tokyo',
            note: null,
        });
    });

    it('keeps the ones that have not ended, soonest first', () => {
        const list = [
            { id: 3, starts_at: '2026-10-20T08:00:00Z', ends_at: '2026-10-20T12:00:00Z' },
            { id: 1, starts_at: '2026-10-10T08:00:00Z', ends_at: '2026-10-15T22:00:00Z' },
            { id: 2, starts_at: '2026-10-14T08:00:00Z', ends_at: '2026-10-16T08:00:00Z' },
        ];

        expect(upcomingExceptions(list, now).map((exception) => exception.id)).toEqual([2, 3]);
    });

    it('words whole days on the zone’s own calendar, the last day included', () => {
        const single = {
            all_day: true,
            timezone: 'Europe/Belgrade',
            starts_at: '2026-10-24T22:00:00Z',
            ends_at: '2026-10-25T23:00:00Z',
            starts_local: '2026-10-25',
            ends_local: '2026-10-25',
        };
        const text = exceptionPeriod(single, 'en-GB');
        expect(text).toContain('25 Oct 2026');
        expect(text).not.toMatch(/26 Oct/);

        const several = { ...single, ends_at: '2026-10-28T23:00:00Z', ends_local: '2026-10-28' };
        const range = exceptionPeriod(several, 'en-GB');
        expect(range).toMatch(/25/);
        expect(range).toMatch(/28 Oct 2026/);
    });

    it('works out the days from the moments when the local values are missing, across the clock change', () => {
        // Sunday 25 October 2026 is 25 hours long in Belgrade (CEST → CET).
        const exception = {
            all_day: true,
            timezone: 'Europe/Belgrade',
            starts_at: '2026-10-24T22:00:00Z',
            ends_at: '2026-10-25T23:00:00Z',
        };

        expect(exceptionDays(exception)).toEqual({ first: '2026-10-25', last: '2026-10-25' });

        // Spring: 29 March 2026 is 23 hours long.
        expect(
            exceptionDays({
                all_day: true,
                timezone: 'Europe/Belgrade',
                starts_at: '2026-03-28T23:00:00Z',
                ends_at: '2026-03-29T22:00:00Z',
            }),
        ).toEqual({ first: '2026-03-29', last: '2026-03-29' });
    });

    it('words times on the clock of the zone they were entered in, across the clock change', () => {
        // 09:00 CEST on Saturday to 09:00 CET on Monday: an hour longer than two days.
        const autumn = exceptionPeriod(
            {
                all_day: false,
                timezone: 'Europe/Belgrade',
                starts_at: '2026-10-24T07:00:00Z',
                ends_at: '2026-10-26T08:00:00Z',
            },
            'en-GB',
        );
        expect(autumn).toMatch(/24 Oct 2026, 09:00/);
        expect(autumn).toMatch(/26 Oct 2026, 09:00/);

        // The same moments read in Tokyo, which has no summer time.
        const tokyo = exceptionPeriod(
            {
                all_day: false,
                timezone: 'Asia/Tokyo',
                starts_at: '2026-10-24T07:00:00Z',
                ends_at: '2026-10-26T08:00:00Z',
            },
            'en-GB',
        );
        expect(tokyo).toMatch(/16:00/);
        expect(tokyo).toMatch(/17:00/);

        // One afternoon: the date once, both times.
        const afternoon = exceptionPeriod(
            {
                all_day: false,
                timezone: 'Europe/Belgrade',
                starts_at: '2026-03-29T11:00:00Z',
                ends_at: '2026-03-29T15:00:00Z',
            },
            'en-GB',
        );
        expect(afternoon).toMatch(/29 Mar 2026/);
        expect(afternoon).toMatch(/13:00/);
        expect(afternoon).toMatch(/17:00/);
        expect(afternoon.match(/29 Mar/g)).toHaveLength(1);
    });
});

describe('booking rules', () => {
    it('offers the spec’s defaults among the choices', () => {
        expect(MIN_NOTICE_CHOICES).toContain(POLICY_DEFAULTS.min_notice_minutes);
        expect(MAX_DAYS_CHOICES).toContain(POLICY_DEFAULTS.max_days_ahead);
        expect(SLOT_STEP_CHOICES).toContain(POLICY_DEFAULTS.slot_step_minutes);
        expect(BUFFER_CHOICES).toContain(POLICY_DEFAULTS.buffer_before_minutes);
        expect(BUFFER_CHOICES).toContain(POLICY_DEFAULTS.buffer_after_minutes);
        expect(CHANGE_UNTIL_CHOICES).toContain(POLICY_DEFAULTS.client_reschedule_until_hours);
        expect(CHANGE_UNTIL_CHOICES).toContain(null);
        expect(CHANGE_UNTIL_CHOICES).toContain(0);
    });

    it('keeps a stored value that is not in the list', () => {
        expect(choicesWith(BUFFER_CHOICES, 15)).toEqual(BUFFER_CHOICES);
        expect(choicesWith(BUFFER_CHOICES, 25)).toEqual([0, 5, 10, 15, 20, 25, 30, 45, 60]);
        expect(choicesWith(BUFFER_CHOICES, 240)).toEqual([...BUFFER_CHOICES, 240]);
        expect(choicesWith(CHANGE_UNTIL_CHOICES, 36)).toEqual([null, 0, 1, 2, 4, 12, 24, 36, 48, 72, 168]);
        expect(choicesWith(CHANGE_UNTIL_CHOICES, null)).toEqual(CHANGE_UNTIL_CHOICES);
        expect(choicesWith(MAX_DAYS_CHOICES, undefined)).toEqual(MAX_DAYS_CHOICES);
        expect(choicesWith(MAX_DAYS_CHOICES, 'x')).toEqual(MAX_DAYS_CHOICES);
        expect(choicesWith(MAX_DAYS_CHOICES, 7)).not.toBe(MAX_DAYS_CHOICES);
    });

    it('words a length of time in its largest whole unit', () => {
        expect(durationParts(0)).toEqual({ unit: 'minutes', count: 0 });
        expect(durationParts(45)).toEqual({ unit: 'minutes', count: 45 });
        expect(durationParts(90)).toEqual({ unit: 'minutes', count: 90 });
        expect(durationParts(120)).toEqual({ unit: 'hours', count: 2 });
        expect(durationParts(720)).toEqual({ unit: 'hours', count: 12 });
        expect(durationParts(1440)).toEqual({ unit: 'days', count: 1 });
        expect(durationParts(4320)).toEqual({ unit: 'days', count: 3 });
        expect(durationParts(10080)).toEqual({ unit: 'weeks', count: 1 });
        expect(durationParts(168 * 60)).toEqual({ unit: 'weeks', count: 1 });
    });

    it('fills the form from the saved rules, defaults for anything missing', () => {
        expect(policyForm(null)).toEqual(POLICY_DEFAULTS);
        expect(
            policyForm({
                enabled: true,
                requires_confirmation: true,
                min_notice_minutes: 0,
                max_days_ahead: 30,
                slot_step_minutes: 15,
                buffer_before_minutes: 5,
                buffer_after_minutes: 0,
                client_reschedule_until_hours: null,
                client_cancel_until_hours: 0,
                client_note: null,
                astrologer: { id: 1, name: 'Mila' },
                bookable_services: 2,
            }),
        ).toEqual({
            enabled: true,
            requires_confirmation: true,
            min_notice_minutes: 0,
            max_days_ahead: 30,
            slot_step_minutes: 15,
            buffer_before_minutes: 5,
            buffer_after_minutes: 0,
            client_reschedule_until_hours: null,
            client_cancel_until_hours: 0,
            client_note: '',
        });
    });

    it('sends exactly the rules, with an empty note as null', () => {
        const payload = policyPayload({ ...POLICY_DEFAULTS, enabled: true, client_note: '  ', extra: 'x' });

        expect(payload).toEqual({
            enabled: true,
            requires_confirmation: false,
            min_notice_minutes: 1440,
            max_days_ahead: 60,
            slot_step_minutes: 30,
            buffer_before_minutes: 0,
            buffer_after_minutes: 15,
            client_reschedule_until_hours: 24,
            client_cancel_until_hours: 24,
            client_note: null,
        });
        expect(policyPayload({ ...POLICY_DEFAULTS, client_note: ' Deposit 30%. ' }).client_note).toBe('Deposit 30%.');
    });

    it('says whether clients can book, and what is missing', () => {
        const ready = {
            enabled: true,
            astrologer: { id: 1, name: 'Mila' },
            bookable_services: 2,
            has_availability: true,
        };

        expect(bookingState(ready)).toEqual({ state: 'open', missing: [] });
        expect(bookingState({ ...ready, enabled: false })).toEqual({ state: 'off', missing: [] });
        expect(bookingState(null)).toEqual({ state: 'off', missing: [] });
        expect(bookingState({ ...ready, bookable_services: 0, has_availability: false })).toEqual({
            state: 'incomplete',
            missing: ['services', 'hours'],
        });
        expect(bookingState({ ...ready, astrologer: null })).toEqual({ state: 'incomplete', missing: ['astrologer'] });
    });
});

describe('settings routes', () => {
    const router = createRouter({ history: createMemoryHistory(), routes });

    it('has a page for working hours and one for booking', () => {
        expect(router.resolve('/settings/availability').name).toBe('settings.availability');
        expect(router.resolve('/settings/booking').name).toBe('settings.booking');
        expect(router.resolve({ name: 'settings.availability' }).meta.verified).toBe(true);
        expect(router.resolve({ name: 'settings.booking' }).meta.verified).toBe(true);
    });
});
