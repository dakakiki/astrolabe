import { describe, expect, it } from 'vitest';

import en from '../../resources/js/i18n/locales/en.json';
import {
    ALWAYS_BY_EMAIL,
    anyChannel,
    channelsForm,
    describeNotice,
    NOTIFICATION_GROUPS,
} from '../../resources/js/lib/inAppNotifications';

const kinds = NOTIFICATION_GROUPS.flatMap((group) => group.kinds);

describe('notifications by kind and channel (docs/spec/09, "Obaveštenja")', () => {
    it('lists the same kinds as the server, each with its channels and its words', () => {
        expect(kinds.map((row) => row.kind)).toEqual([
            'appointment_reminder',
            'task_digest',
            'portal_booked',
            'portal_requested',
            'portal_moved',
            'portal_move_requested',
            'portal_cancelled',
            'export_ready',
            'practice_deletion_scheduled',
            'practice_deletion_cancelled',
            'practice_deleted',
        ]);
        // A deleted practice has no app to show a notice in.
        expect(kinds.find((row) => row.kind === 'practice_deleted').channels).toEqual(['mail']);

        kinds.forEach(({ kind }) => {
            expect(en.settings.notifications.kinds[kind].label).toBeTruthy();
            expect(en.settings.notifications.kinds[kind].hint).toBeTruthy();
        });
        NOTIFICATION_GROUPS.forEach((group) => expect(en.settings.notifications.groups[group.key]).toBeTruthy());
        ALWAYS_BY_EMAIL.forEach((key) => expect(en.settings.notifications.always[key].label).toBeTruthy());
    });

    it('starts the form from what is stored, every channel on unless stored off', () => {
        const form = channelsForm({ channels: { portal_moved: { mail: false, app: true } } });

        expect(form.portal_moved).toEqual({ mail: false, app: true });
        expect(form.appointment_reminder).toEqual({ mail: true, app: true });
        expect(form.practice_deleted).toEqual({ mail: true });
        expect(channelsForm(null).task_digest).toEqual({ mail: true, app: true });

        expect(anyChannel(form, 'portal_moved')).toBe(true);
        expect(anyChannel({ task_digest: { mail: false, app: false } }, 'task_digest')).toBe(false);
    });
});

describe('a notice in the bell', () => {
    const notice = (kind, data = {}, client = { id: 4, full_name: 'Ana Portal' }) => ({ id: 1, kind, data, client });

    it('names the client and leads to the appointment in the calendar', () => {
        expect(
            describeNotice(notice('portal_booked', { appointment_id: 7, starts_at: '2026-12-21T09:30:00Z' })),
        ).toEqual({
            icon: 'addAppointment',
            title: 'bell.portal_booked',
            client: 'Ana Portal',
            at: '2026-12-21T09:30:00Z',
            to: { name: 'calendar', query: { appointment: 7 } },
        });
        expect(
            describeNotice(
                notice('portal_move_requested', {
                    appointment_id: 7,
                    starts_at: '2026-12-22T09:00:00Z',
                    previous_starts_at: '2026-12-21T09:30:00Z',
                }),
            ),
        ).toMatchObject({ icon: 'reschedule', from: '2026-12-21T09:30:00Z', at: '2026-12-22T09:00:00Z' });
        // A client deleted since: the notice stays, without a name.
        expect(describeNotice(notice('appointment_reminder', { appointment_id: 7 }, null)).client).toBeNull();
    });

    it('counts the morning tasks and points the practice notices at Your data', () => {
        expect(describeNotice(notice('task_digest', { today: 3, overdue: 1 }))).toMatchObject({
            count: 3,
            overdue: 1,
            to: { name: 'tasks.index', query: { show: 'today' } },
        });
        expect(describeNotice(notice('export_ready', { expires_at: '2026-11-25T10:00:00Z' })).to).toEqual({
            name: 'settings.data',
        });
        expect(describeNotice(notice('invoice')).title).toBe('bell.unknown');
    });

    it('has words for every kind it can show', () => {
        kinds
            .filter((row) => row.channels.includes('app'))
            .forEach(({ kind }) => {
                const key = describeNotice(notice(kind)).title.split('.')[1];
                expect(en.bell[key]).toBeTruthy();
            });
    });
});
