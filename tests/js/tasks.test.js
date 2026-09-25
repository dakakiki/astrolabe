import { describe, expect, it } from 'vitest';

import { describeDue, dueMoment, followUpDate, taskPayload, viewParams } from '@/lib/tasks';

const task = (fields) => ({
    status: 'open',
    due_date: null,
    due_time: null,
    due_at: null,
    timezone: 'Europe/Belgrade',
    due_state: null,
    ...fields,
});

describe('viewParams', () => {
    it('maps the tabs to the API filters', () => {
        expect(viewParams('open')).toEqual({ status: 'open' });
        expect(viewParams('overdue')).toEqual({ due: 'overdue' });
        expect(viewParams('today')).toEqual({ due: 'today' });
        expect(viewParams('done')).toEqual({ status: 'done' });
        expect(viewParams('whatever')).toEqual({ status: 'open' });
    });
});

describe('dueMoment', () => {
    it('keeps the date and time as entered in the viewer’s own zone', () => {
        const due = task({ due_date: '2026-10-07', due_time: '15:00', due_at: '2026-10-07T13:00:00Z' });

        expect(dueMoment(due, 'Europe/Belgrade')).toEqual({ date: '2026-10-07', time: '15:00', converted: false });
        expect(dueMoment(task({}), 'Europe/Belgrade')).toBeNull();
    });

    it('moves a time set in another zone onto the viewer’s clock, even across midnight', () => {
        const due = task({
            due_date: '2026-10-07',
            due_time: '20:00',
            due_at: '2026-10-08T00:00:00Z',
            timezone: 'America/New_York',
        });

        expect(dueMoment(due, 'Europe/Belgrade')).toEqual({ date: '2026-10-08', time: '02:00', converted: true });
    });

    it('leaves a whole day alone whatever the zone', () => {
        const due = task({ due_date: '2026-10-07', due_at: '2026-10-08T04:00:00Z', timezone: 'America/New_York' });

        expect(dueMoment(due, 'Europe/Belgrade')).toEqual({ date: '2026-10-07', time: null, converted: false });
    });
});

describe('describeDue', () => {
    const today = '2026-10-05';
    const zone = 'Europe/Belgrade';

    it('counts whole days overdue, or says since when for earlier today', () => {
        expect(describeDue(task({ due_date: '2026-10-02', due_state: 'overdue' }), today, zone)).toEqual({
            key: 'tasks.due.overdueDays',
            params: { date: '2026-10-02', time: null, count: 3 },
            tone: 'danger',
        });

        expect(
            describeDue(task({ due_date: '2026-10-05', due_time: '09:00', due_state: 'overdue' }), today, zone),
        ).toMatchObject({ key: 'tasks.due.overdueSince', params: { time: '09:00' }, tone: 'danger' });
    });

    it('marks today and names tomorrow', () => {
        expect(describeDue(task({ due_date: today, due_state: 'today' }), today, zone)).toMatchObject({
            key: 'tasks.due.today',
            tone: 'warn',
        });
        expect(
            describeDue(task({ due_date: today, due_time: '18:00', due_state: 'today' }), today, zone),
        ).toMatchObject({ key: 'tasks.due.todayAt', params: { time: '18:00' } });
        expect(describeDue(task({ due_date: '2026-10-06', due_state: 'upcoming' }), today, zone)).toMatchObject({
            key: 'tasks.due.tomorrow',
            tone: '',
        });
    });

    it('gives a date further ahead, and a plain date once done', () => {
        expect(describeDue(task({ due_date: '2026-10-12', due_state: 'upcoming' }), today, zone)).toMatchObject({
            key: 'tasks.due.on',
            params: { date: '2026-10-12' },
        });
        expect(describeDue(task({ status: 'done', due_date: '2026-10-02', due_time: '10:00' }), today, zone)).toEqual({
            key: 'tasks.due.onAt',
            params: { date: '2026-10-02', time: '10:00' },
            tone: '',
        });
        expect(describeDue(task({}), today, zone)).toBeNull();
    });
});

describe('followUpDate', () => {
    it('suggests a week later, across month ends', () => {
        expect(followUpDate('2026-10-05')).toBe('2026-10-12');
        expect(followUpDate('2026-12-28')).toBe('2027-01-04');
        expect(followUpDate('2026-10-05', 1)).toBe('2026-10-06');
    });
});

describe('taskPayload', () => {
    it('sends empty fields as null and drops a time without a day', () => {
        expect(
            taskPayload({ title: '  Send it  ', description: ' ', priority: '', due_date: '', due_time: '15:00' }),
        ).toEqual({ title: 'Send it', description: null, priority: 'normal', due_date: null, due_time: null });
        expect(
            taskPayload({
                title: 'Call',
                description: 'About Saturn',
                priority: 'high',
                due_date: '2026-10-07',
                due_time: '',
            }),
        ).toEqual({
            title: 'Call',
            description: 'About Saturn',
            priority: 'high',
            due_date: '2026-10-07',
            due_time: null,
        });
    });
});
