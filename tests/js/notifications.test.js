import { describe, expect, it } from 'vitest';

import {
    leadTime,
    preferencesPayload,
    quietHoursForm,
    reminderChoices,
    reminderState,
    REMINDER_CHOICES,
} from '@/lib/notifications';

const appointment = (fields) => ({
    status: 'scheduled',
    starts_at: '2026-10-05T13:00:00Z',
    reminder_minutes: 1440,
    remind_at: '2026-10-04T13:00:00Z',
    reminder_sent_at: null,
    ...fields,
});

describe('leadTime', () => {
    it('words a lead time in its largest whole unit', () => {
        expect(leadTime(15)).toEqual({ unit: 'minutes', count: 15 });
        expect(leadTime(90)).toEqual({ unit: 'minutes', count: 90 });
        expect(leadTime(120)).toEqual({ unit: 'hours', count: 2 });
        expect(leadTime(1440)).toEqual({ unit: 'days', count: 1 });
        expect(leadTime(2880)).toEqual({ unit: 'days', count: 2 });
    });
});

describe('reminderChoices', () => {
    it('offers the usual lead times and keeps one set elsewhere', () => {
        expect(reminderChoices()).toEqual(REMINDER_CHOICES);
        expect(reminderChoices(1440)).toEqual(REMINDER_CHOICES);
        expect(reminderChoices(90)).toEqual([15, 30, 60, 90, 120, 180, 360, 720, 1440, 2880]);
        expect(reminderChoices(null)).toEqual(REMINDER_CHOICES);
    });
});

describe('reminderState', () => {
    it('says when a planned reminder goes out, and whether quiet hours moved it', () => {
        expect(reminderState(appointment({}))).toEqual({
            state: 'planned',
            at: '2026-10-04T13:00:00Z',
            shifted: false,
        });
        expect(reminderState(appointment({ remind_at: '2026-10-04T06:00:00Z' })).shifted).toBe(true);
    });

    it('tells a sent reminder, none, one switched off and one whose time had passed', () => {
        expect(reminderState(appointment({ reminder_sent_at: '2026-10-04T13:00:10Z' }))).toEqual({
            state: 'sent',
            at: '2026-10-04T13:00:10Z',
        });
        expect(reminderState(appointment({ reminder_minutes: null, remind_at: null })).state).toBe('none');
        expect(reminderState(appointment({ remind_at: null }), { appointment_reminders: false }).state).toBe('paused');
        expect(reminderState(appointment({ remind_at: null }), { appointment_reminders: true }).state).toBe('passed');
        expect(reminderState(appointment({ remind_at: null })).state).toBe('passed');
    });

    it('has nothing to say about a held or cancelled appointment unless a reminder went out', () => {
        expect(reminderState(appointment({ status: 'completed', remind_at: null }))).toBeNull();
        expect(reminderState(appointment({ status: 'cancelled', remind_at: null }))).toBeNull();
        expect(reminderState(appointment({ status: 'completed', reminder_sent_at: '2026-10-04T13:00:10Z' })).state).toBe(
            'sent',
        );
    });
});

describe('the settings form', () => {
    it('edits quiet hours as a switch and two times, and sends null when off', () => {
        expect(quietHoursForm({ quiet_hours: { start: '23:00', end: '07:00' } })).toEqual({
            enabled: true,
            start: '23:00',
            end: '07:00',
        });
        expect(quietHoursForm({ quiet_hours: null })).toEqual({ enabled: false, start: '22:00', end: '08:00' });

        const form = {
            appointment_reminders: true,
            reminder_minutes: 120,
            task_digest: false,
            digest_time: '08:00',
            quiet_enabled: false,
            quiet_start: '22:00',
            quiet_end: '08:00',
        };
        expect(preferencesPayload(form)).toEqual({
            appointment_reminders: true,
            reminder_minutes: 120,
            task_digest: false,
            digest_time: '08:00',
            quiet_hours: null,
        });
        expect(preferencesPayload({ ...form, quiet_enabled: true }).quiet_hours).toEqual({
            start: '22:00',
            end: '08:00',
        });
    });
});
