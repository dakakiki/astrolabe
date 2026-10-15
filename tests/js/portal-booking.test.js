import { describe, expect, it } from 'vitest';

import {
    bookingPayload,
    bookingProblem,
    changeDeadlines,
    changeOptions,
    changeRules,
    dayLabel,
    dayOf,
    dayZoneMoment,
    daysAhead,
    findSlot,
    groupSlotsByDay,
    idempotencyKey,
    nextDay,
    priceText,
    readBookingQuery,
    readMoment,
    sameMoment,
    slotLabels,
    slotRange,
    slotTime,
    statusBadge,
    withoutMoment,
    zoneName,
} from '../../resources/js/portal/lib/booking';
import { zoneLabel } from '../../resources/js/portal/lib/portal';
import en from '../../resources/js/portal/locales/en.json';

const slot = (starts, minutes = 60) => ({
    starts_at: starts,
    ends_at: new Date(Date.parse(starts) + minutes * 60_000).toISOString().replace('.000Z', 'Z'),
});

const london = 'Europe/London';
const newYork = 'America/New_York';

describe('free times by day', () => {
    // London goes back to GMT on 25 October 2026 at 01:00 UTC; New York on 1 November at 06:00 UTC.
    const autumn = [
        slot('2026-10-26T00:00:00Z'),
        slot('2026-10-24T22:30:00Z'),
        slot('2026-10-24T23:30:00Z'),
        slot('2026-10-25T00:30:00Z'),
        slot('2026-10-25T09:00:00Z'),
        slot('2026-10-25T23:30:00Z'),
    ];

    it("puts each time on the client's own day across the change to winter time in London", () => {
        const days = groupSlotsByDay(autumn, london);

        expect(days.map((day) => day.date)).toEqual(['2026-10-24', '2026-10-25', '2026-10-26']);
        expect(days[0].slots.map((item) => slotTime(item.starts_at, 'en-GB', london))).toEqual(['23:30']);
        // 23:30 UTC on the 24th is 00:30 BST on the 25th; 23:30 UTC on the 25th is 23:30 GMT.
        expect(days[1].slots.map((item) => slotTime(item.starts_at, 'en-GB', london))).toEqual([
            '00:30',
            '01:30',
            '09:00',
            '23:30',
        ]);
        expect(days[2].slots.map((item) => slotTime(item.starts_at, 'en-GB', london))).toEqual(['00:00']);
    });

    it('groups the same moments differently for a client in New York', () => {
        const days = groupSlotsByDay(autumn, newYork);

        expect(days.map((day) => [day.date, day.slots.length])).toEqual([
            ['2026-10-24', 3],
            ['2026-10-25', 3],
        ]);
        expect(days[1].slots.map((item) => slotTime(item.starts_at, 'en-GB', newYork))).toEqual([
            '05:00',
            '19:30',
            '20:00',
        ]);
    });

    it('keeps an hour that happens twice in New York in order, told apart by the zone name', () => {
        const days = groupSlotsByDay([slot('2026-11-01T06:30:00Z', 30), slot('2026-11-01T05:30:00Z', 30)], newYork);

        expect(days).toHaveLength(1);
        expect(days[0].date).toBe('2026-11-01');
        expect(days[0].slots.map((item) => item.starts_at)).toEqual(['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z']);
        expect(days[0].slots.map((item) => slotTime(item.starts_at, 'en-GB', newYork))).toEqual(['01:30', '01:30']);
        expect(zoneLabel(newYork, '2026-11-01T05:30:00Z', 'en-US')).toBe('New York (EDT)');
        expect(zoneLabel(newYork, '2026-11-01T06:30:00Z', 'en-US')).toBe('New York (EST)');
    });

    it('labels the two 1:30 AM buttons of the night New York goes back an hour with their zones', () => {
        const night = [
            slot('2026-11-01T05:30:00Z', 30),
            slot('2026-11-01T06:30:00Z', 30),
            slot('2026-11-01T07:30:00Z', 30),
        ];
        const labels = slotLabels(night, 'en-US', newYork);

        // ICU writes a narrow no-break space before "AM" in newer versions.
        expect(labels[0].time).toMatch(/^1:30\sAM$/u);
        expect(labels[0].zone).toBe('EDT');
        expect(labels[1].time).toBe(labels[0].time);
        expect(labels[1].zone).toBe('EST');
        // A time that happens once on the clock reads plainly.
        expect(labels[2]).toEqual({ time: slotTime('2026-11-01T07:30:00Z', 'en-US', newYork), zone: '' });
        expect(zoneName('2026-11-01T05:30:00Z', 'en-US', newYork)).toBe('EDT');
        expect(zoneName('2026-11-01T06:30:00Z', 'en-US', newYork)).toBe('EST');
    });

    it('labels an ordinary day plainly and copes with nothing to label', () => {
        const day = [slot('2026-10-26T09:00:00Z'), slot('2026-10-26T10:00:00Z')];

        expect(slotLabels(day, 'en-GB', london)).toEqual([
            { time: '09:00', zone: '' },
            { time: '10:00', zone: '' },
        ]);
        expect(slotLabels([], 'en-GB', london)).toEqual([]);
        expect(slotLabels(null, 'en-GB', london)).toEqual([]);
        expect(zoneName('2026-13-01T10:00Z', 'en-US', newYork)).toBe('');
        expect(zoneName('2026-11-01T05:30:00Z', 'en-US', 'Not/AZone')).toBe('');
    });

    it("names the day's zone after the chosen time when it is on that day, else after the first time", () => {
        const night = [slot('2026-11-01T05:30:00Z', 30), slot('2026-11-01T06:30:00Z', 30)];

        expect(dayZoneMoment(night)).toBe('2026-11-01T05:30:00Z');
        expect(dayZoneMoment(night, '2026-11-01T06:30:00.000Z')).toBe('2026-11-01T06:30:00Z');
        expect(zoneLabel(newYork, dayZoneMoment(night, '2026-11-01T06:30:00Z'), 'en-US')).toBe('New York (EST)');
        // A time chosen on another day leaves this day named after its own first time.
        expect(dayZoneMoment(night, '2026-11-02T15:00:00Z')).toBe('2026-11-01T05:30:00Z');
        expect(dayZoneMoment([], '2026-11-01T06:30:00Z')).toBeNull();
        expect(dayZoneMoment(null)).toBeNull();
    });

    it('reads a time and its end on the wall clock of the zone', () => {
        expect(slotRange(slot('2026-10-24T09:00:00Z', 90), 'en-GB', london)).toBe('10:00 – 11:30');
        expect(slotRange(slot('2026-10-26T09:00:00Z', 90), 'en-GB', london)).toBe('09:00 – 10:30');
        expect(slotRange(slot('2026-10-26T09:00:00Z', 90), 'en-GB', 'Asia/Tokyo')).toBe('18:00 – 19:30');
    });

    it('treats nothing and an unknown zone calmly', () => {
        expect(groupSlotsByDay([], london)).toEqual([]);
        expect(groupSlotsByDay(null, london)).toEqual([]);
        expect(dayOf('2026-10-25T23:30:00Z', 'Not/AZone')).toBe('2026-10-25');
        expect(slotTime('2026-10-25T23:30:00Z', 'en-GB', 'Not/AZone')).toBe('23:30');
    });

    it('reads a moment that is no real time as no day and no time, instead of throwing', () => {
        for (const impossible of ['2026-13-01T10:00Z', '2026-10-20T25:00Z', 'soon', null]) {
            expect(dayOf(impossible, london)).toBeNull();
            expect(slotTime(impossible, 'en-GB', london)).toBe('');
        }
        // The picker then opens on the first day with times.
        expect(nextDay([{ date: '2026-10-20' }, { date: '2026-10-22' }], dayOf('2026-13-01T10:00Z', london))).toBe(
            '2026-10-20',
        );
    });

    it('names a day in parts for the strip and in full, whatever the zone of the device', () => {
        const day = dayLabel('2026-10-25', 'en-GB');

        expect(day.weekday).toBe('Sun');
        expect(day.day).toBe('25');
        expect(day.month).toBe('Oct');
        // ICU versions differ on the comma after the weekday.
        expect(day.full).toMatch(/^Sunday,? 25 October$/);
    });

    it('finds the chosen time by its moment, not by its spelling', () => {
        const list = [slot('2026-10-25T09:00:00Z'), slot('2026-10-25T10:00:00Z')];

        expect(findSlot(list, '2026-10-25T10:00:00.000Z')).toBe(list[1]);
        expect(findSlot(list, '2026-10-25T11:00:00+01:00')).toBe(list[1]);
        expect(findSlot(list, '2026-10-25T11:00:00Z')).toBeNull();
        expect(findSlot(list, null)).toBeNull();
        expect(findSlot(list, 'soon')).toBeNull();
        expect(findSlot(null, '2026-10-25T10:00:00Z')).toBeNull();
    });
});

describe('the day shown after the list changed', () => {
    const groups = [{ date: '2026-10-20' }, { date: '2026-10-22' }, { date: '2026-10-27' }];

    it('starts on the first day', () => {
        expect(nextDay(groups)).toBe('2026-10-20');
    });

    it('stays on the day while it has times, else moves to the next one that has', () => {
        expect(nextDay(groups, '2026-10-22')).toBe('2026-10-22');
        expect(nextDay(groups, '2026-10-21')).toBe('2026-10-22');
        expect(nextDay(groups, '2026-10-23')).toBe('2026-10-27');
    });

    it('falls back to the last day, and to nothing when no time is free', () => {
        expect(nextDay(groups, '2026-11-02')).toBe('2026-10-27');
        expect(nextDay([], '2026-10-22')).toBeNull();
        expect(nextDay(null)).toBeNull();
    });
});

describe('prices and reach', () => {
    it('shows the price for information in the viewer locale', () => {
        expect(priceText({ amount: 9000, currency: 'EUR', decimals: 2 }, 'en-GB')).toBe('€90');
        expect(priceText({ amount: 4950, currency: 'EUR', decimals: 2 }, 'en-GB')).toBe('€49.50');
        expect(priceText({ amount: 4900, currency: 'JPY', decimals: 0 }, 'en-US')).toBe('¥4,900');
        expect(priceText({ amount: 6500, currency: 'USD' }, 'en-US')).toBe('$65');
        expect(priceText(null, 'en')).toBeNull();
    });

    it('counts the days the list reaches', () => {
        const now = new Date('2026-10-15T10:00:00Z');

        expect(daysAhead('2026-12-14T10:00:00Z', now)).toBe(60);
        expect(daysAhead('2026-10-15T12:00:00Z', now)).toBe(1);
        expect(daysAhead(null, now)).toBe(1);
    });
});

describe('change rules', () => {
    it('says one line when rescheduling and cancelling end together', () => {
        expect(changeRules(24, 24)).toEqual([{ key: 'both', count: 24 }]);
        expect(changeRules(0, 0)).toEqual([{ key: 'both', count: 0 }]);
    });

    it('says the practice decides when neither is allowed here', () => {
        expect(changeRules(null, null)).toEqual([{ key: 'none' }]);
        expect(changeRules(undefined, undefined)).toEqual([{ key: 'none' }]);
    });

    it('says each one when they differ', () => {
        expect(changeRules(48, 24)).toEqual([
            { key: 'reschedule', count: 48 },
            { key: 'cancel', count: 24 },
        ]);
        expect(changeRules(24, null)).toEqual([{ key: 'reschedule', count: 24 }, { key: 'cancelNone' }]);
        expect(changeRules(null, 12)).toEqual([{ key: 'rescheduleNone' }, { key: 'cancel', count: 12 }]);
    });

    it('has the words for every line', () => {
        for (const key of ['none', 'both', 'reschedule', 'cancel', 'rescheduleNone', 'cancelNone']) {
            expect(en.portal.booking.rules[key]).toBeTruthy();
        }
        // "until the appointment starts | 1 hour | N hours": three forms, so 0 and 1 read right.
        expect(en.portal.booking.rules.both.split('|')).toHaveLength(3);
    });

    it("tells until when an upcoming appointment can be changed, from the server's deadlines", () => {
        const both = {
            can_reschedule: true,
            can_cancel: true,
            reschedule_until: '2026-10-19T09:00:00Z',
            cancel_until: '2026-10-19T09:00:00.000Z',
        };

        expect(changeDeadlines(both)).toEqual([{ key: 'both', at: '2026-10-19T09:00:00Z' }]);
        expect(changeDeadlines({ ...both, cancel_until: '2026-10-18T09:00:00Z' })).toEqual([
            { key: 'reschedule', at: '2026-10-19T09:00:00Z' },
            { key: 'cancel', at: '2026-10-18T09:00:00Z' },
        ]);
        expect(changeDeadlines({ ...both, can_reschedule: false })).toEqual([
            { key: 'cancel', at: '2026-10-19T09:00:00.000Z' },
        ]);
        expect(changeDeadlines({ ...both, can_reschedule: false, can_cancel: false })).toEqual([]);
        for (const key of ['both', 'reschedule', 'cancel']) {
            expect(en.portal.appointments.until[key]).toContain('{when}');
        }
    });

    it('offers rescheduling and cancelling only while allowed, else the practice', () => {
        const upcoming = { upcoming: true, can_reschedule: true, can_cancel: false };

        expect(changeOptions(upcoming)).toEqual({ reschedule: true, cancel: false, contact: false });
        expect(changeOptions({ ...upcoming, can_reschedule: false })).toEqual({
            reschedule: false,
            cancel: false,
            contact: true,
        });
        expect(changeOptions({ upcoming: false, can_reschedule: true, can_cancel: true })).toEqual({
            reschedule: false,
            cancel: false,
            contact: false,
        });
    });
});

describe('status badges', () => {
    it('shows none for a confirmed appointment ahead, and marks a request waiting', () => {
        expect(statusBadge({ status: 'scheduled', upcoming: true })).toBeNull();
        expect(statusBadge({ status: 'requested', upcoming: true })).toEqual({ key: 'requested', tone: 'b-warn' });
    });

    it('tells who cancelled and what the practice did not confirm', () => {
        expect(statusBadge({ status: 'cancelled', upcoming: false, declined: true, cancelled_by: 'practice' })).toEqual(
            {
                key: 'declined',
                tone: '',
            },
        );
        expect(statusBadge({ status: 'cancelled', upcoming: false, declined: false, cancelled_by: 'client' })).toEqual({
            key: 'cancelled_by_client',
            tone: '',
        });
        expect(statusBadge({ status: 'cancelled', upcoming: false, cancelled_by: 'practice' }).key).toBe('cancelled');
        // A request whose time passed without an answer was never confirmed.
        expect(statusBadge({ status: 'requested', upcoming: false }).key).toBe('declined');
    });

    it('keeps the past labels of 9a', () => {
        expect(statusBadge({ status: 'completed', upcoming: false })).toEqual({ key: 'completed', tone: 'b-ok' });
        expect(statusBadge({ status: 'no_show', upcoming: false })).toEqual({ key: 'no_show', tone: 'b-warn' });
        expect(statusBadge({ status: 'scheduled', upcoming: false })).toEqual({ key: 'scheduled', tone: 'b-info' });
    });

    it('has the words for every badge', () => {
        for (const key of [
            'scheduled',
            'completed',
            'cancelled',
            'no_show',
            'requested',
            'declined',
            'cancelled_by_client',
        ]) {
            expect(en.portal.appointments.status[key]).toBeTruthy();
        }
    });
});

describe('booking requests', () => {
    it('makes a fresh key the server accepts for each confirmation', () => {
        const first = idempotencyKey();
        const second = idempotencyKey();

        expect(first).toMatch(/^booking-[0-9a-f]{32}$/);
        expect(first).toMatch(/^[A-Za-z0-9_\-:.]{8,100}$/);
        expect(second).not.toBe(first);
        expect(idempotencyKey('reschedule')).toMatch(/^reschedule-[0-9a-f]{32}$/);
    });

    it('reads the step from the address and ignores what does not fit', () => {
        expect(readBookingQuery({})).toEqual({ serviceId: null, at: null });
        expect(readBookingQuery({ service: '12' })).toEqual({ serviceId: 12, at: null });
        expect(readBookingQuery({ service: '12', at: '2026-10-25T09:00:00Z' })).toEqual({
            serviceId: 12,
            at: '2026-10-25T09:00:00Z',
        });
        expect(readBookingQuery({ service: 'abc', at: '2026-10-25T09:00:00Z' })).toEqual({ serviceId: null, at: null });
        expect(readBookingQuery({ service: ['1', '2'] })).toEqual({ serviceId: null, at: null });
        expect(readBookingQuery({ service: '12', at: 'tomorrow' })).toEqual({ serviceId: 12, at: null });
        expect(readBookingQuery({ service: '12', at: '2026-10-25 09:00' })).toEqual({ serviceId: 12, at: null });
        expect(readMoment('2026-10-25T09:00:00.000Z')).toBe('2026-10-25T09:00:00.000Z');
        expect(readMoment('<script>')).toBeNull();
        expect(readMoment(undefined)).toBeNull();
    });

    it('ignores a time in the address that has the right shape but is no real time', () => {
        for (const impossible of [
            '2026-13-01T10:00Z',
            '2026-10-20T25:00Z',
            '2026-10-20T10:61Z',
            '2026-10-20T10:00+25:00',
        ]) {
            expect(readBookingQuery({ service: '12', at: impossible })).toEqual({ serviceId: 12, at: null });
            expect(readMoment(impossible)).toBeNull();
        }
        expect(readMoment('2026-10-20T10:00+02:00')).toBe('2026-10-20T10:00+02:00');
    });

    it("leaves the appointment's own time out of the times to move it to", () => {
        const list = [slot('2026-10-30T09:00:00Z'), slot('2026-10-30T10:00:00Z'), slot('2026-10-30T11:00:00Z')];

        expect(withoutMoment(list, '2026-10-30T11:00:00+01:00')).toEqual([list[0], list[2]]);
        expect(withoutMoment(list, '2026-10-30T12:00:00Z')).toEqual(list);
        // Before the appointment is known, nothing is left out.
        expect(withoutMoment(list, null)).toEqual(list);
        expect(withoutMoment(null, '2026-10-30T10:00:00Z')).toBeNull();
    });

    it('tells when a move kept the time the appointment already had', () => {
        expect(sameMoment('2026-10-30T10:00:00Z', '2026-10-30T10:00:00.000Z')).toBe(true);
        expect(sameMoment('2026-10-30T10:00:00Z', '2026-10-30T11:00:00+01:00')).toBe(true);
        expect(sameMoment('2026-10-30T10:00:00Z', '2026-10-30T10:30:00Z')).toBe(false);
        expect(sameMoment(null, null)).toBe(false);
        expect(sameMoment('soon', 'soon')).toBe(false);
    });

    it('sends the place only for a service held either way, and a message only when written', () => {
        const either = { id: 4, location_type: 'either' };
        const online = { id: 5, location_type: 'online' };
        const startsAt = '2026-10-25T09:00:00Z';

        expect(bookingPayload({ service: either, startsAt, location: 'in_person', message: '  Hello  ' })).toEqual({
            service_id: 4,
            starts_at: startsAt,
            location_type: 'in_person',
            message: 'Hello',
        });
        expect(bookingPayload({ service: online, startsAt, location: 'in_person', message: '   ' })).toEqual({
            service_id: 5,
            starts_at: startsAt,
        });
        expect(bookingPayload({ service: either, startsAt })).toEqual({ service_id: 4, starts_at: startsAt });
    });

    it('tells what an answer from the server means for the screen', () => {
        const answer = (status, data = {}) => ({ response: { status, data } });
        const fresh = [slot('2026-10-25T10:00:00Z')];

        expect(bookingProblem(answer(409, { code: 'slot_taken', slots: fresh }))).toEqual({
            code: 'slot_taken',
            slots: fresh,
        });
        expect(bookingProblem(answer(409, { code: 'slot_taken' }))).toEqual({ code: 'slot_taken', slots: null });
        expect(bookingProblem(answer(409, { code: 'change_too_late' }))).toEqual({ code: 'change_too_late' });
        expect(bookingProblem(answer(409, { code: 'not_changeable' }))).toEqual({ code: 'not_changeable' });
        expect(bookingProblem(answer(403, { code: 'booking_disabled' }))).toEqual({ code: 'booking_disabled' });
        // The portal's own handlers already moved the person on.
        expect(bookingProblem(answer(403, { code: 'portal_no_access' }))).toEqual({ code: 'handled' });
        expect(bookingProblem(answer(401))).toEqual({ code: 'handled' });
        expect(bookingProblem(answer(404))).toEqual({ code: 'not_found' });
        expect(bookingProblem(answer(422, { errors: { message: ['Too long.'] } }))).toEqual({
            code: 'invalid',
            fields: true,
        });
        expect(bookingProblem(answer(429))).toEqual({ code: 'too_many' });
        expect(bookingProblem(answer(409, { code: 'something_else' }))).toEqual({ code: 'generic' });
        expect(bookingProblem(new Error('Network Error'))).toEqual({ code: 'generic' });
    });

    it('tells a refusal that names no field apart: the same key already went through with other details', () => {
        const answer = (status, data = {}) => ({ response: { status, data } });
        const reused = { message: 'This Idempotency-Key was already used for a different request.' };

        expect(bookingProblem(answer(422, reused))).toEqual({ code: 'invalid', fields: false });
        expect(bookingProblem(answer(422, { ...reused, errors: {} }))).toEqual({ code: 'invalid', fields: false });
        expect(bookingProblem(answer(422))).toEqual({ code: 'invalid', fields: false });
        expect(en.portal.booking.maybeBooked).toBe(
            'This booking may already have gone through — please check your appointments.',
        );
        expect(en.portal.appointments.maybeMoved).toBeTruthy();
    });

    it('tells the client calmly when they already hold as many upcoming appointments as the portal books', () => {
        const answer = { response: { status: 409, data: { code: 'too_many_open', message: 'Too many.' } } };

        expect(bookingProblem(answer)).toEqual({ code: 'too_many_open' });
        expect(en.portal.booking.tooManyOpen).toBe(
            'You already have several upcoming appointments. Please contact the practice to book more.',
        );
    });

    it('has the words for a move that kept the time', () => {
        expect(en.portal.appointments.unchanged).toBeTruthy();
    });
});
