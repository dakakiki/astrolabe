/**
 * Notifications by kind and channel (docs/spec/09, "Obaveštenja"; user, 18 Nov 2026):
 * each one a person may turn off has a row in Settings → Notifications with email
 * and "in the app" (the bell in the header). The account and security emails always
 * go and are listed apart. Kinds and channels mirror NotificationPreferences::KINDS.
 */

export const CHANNELS = ['mail', 'app'];

/** The rows of Settings → Notifications, in groups; `channels` the ones a kind can take. */
export const NOTIFICATION_GROUPS = [
    {
        key: 'work',
        kinds: [
            { kind: 'appointment_reminder', channels: ['mail', 'app'] },
            { kind: 'task_digest', channels: ['mail', 'app'] },
        ],
    },
    {
        key: 'portal',
        kinds: [
            { kind: 'portal_booked', channels: ['mail', 'app'] },
            { kind: 'portal_requested', channels: ['mail', 'app'] },
            { kind: 'portal_moved', channels: ['mail', 'app'] },
            { kind: 'portal_move_requested', channels: ['mail', 'app'] },
            { kind: 'portal_cancelled', channels: ['mail', 'app'] },
        ],
    },
    {
        key: 'practice',
        kinds: [
            { kind: 'export_ready', channels: ['mail', 'app'] },
            { kind: 'practice_deletion_scheduled', channels: ['mail', 'app'] },
            { kind: 'practice_deletion_cancelled', channels: ['mail', 'app'] },
            // The practice is gone: there is no app left to show it in.
            { kind: 'practice_deleted', channels: ['mail'] },
        ],
    },
];

/** Always by email, whatever is chosen above: they keep the account safe and reachable. */
export const ALWAYS_BY_EMAIL = ['verify', 'reset', 'invitation', 'accountNotice'];

/** The form's copy of the stored channels: every kind and channel, on unless stored off. */
export function channelsForm(preferences) {
    const stored = preferences?.channels ?? {};
    const form = {};

    NOTIFICATION_GROUPS.forEach((group) =>
        group.kinds.forEach(({ kind, channels }) => {
            form[kind] = {};
            channels.forEach((channel) => {
                form[kind][channel] = stored[kind]?.[channel] ?? true;
            });
        }),
    );

    return form;
}

/** Whether a kind is on on any channel (a reminder or the morning count is planned at all). */
export function anyChannel(channels, kind) {
    return Object.values(channels?.[kind] ?? {}).some(Boolean);
}

function calendar(id) {
    return id ? { name: 'calendar', query: { appointment: id } } : { name: 'calendar' };
}

/**
 * How one notice in the bell reads. Translation keys and parameters, not text;
 * `at` / `from` / `day` are moments (UTC) the component formats on the viewer's
 * clock, `client` the name read with the list (null when the client is gone).
 */
export function describeNotice(item) {
    const data = item.data ?? {};
    const client = item.client?.full_name ?? null;

    switch (item.kind) {
        case 'appointment_reminder':
            return {
                icon: 'calendar',
                title: 'bell.reminder',
                client,
                at: data.starts_at,
                to: calendar(data.appointment_id),
            };
        case 'task_digest':
            return {
                icon: 'tasks',
                title: 'bell.digest',
                count: data.today ?? 0,
                overdue: data.overdue ?? 0,
                to: { name: 'tasks.index', query: { show: 'today' } },
            };
        case 'portal_booked':
        case 'portal_requested':
        case 'portal_cancelled':
            return {
                icon: item.kind === 'portal_cancelled' ? 'cancelAppointment' : 'addAppointment',
                title: `bell.${item.kind}`,
                client,
                at: data.starts_at,
                to: calendar(data.appointment_id),
            };
        case 'portal_moved':
        case 'portal_move_requested':
            return {
                icon: 'reschedule',
                title: `bell.${item.kind}`,
                client,
                from: data.previous_starts_at ?? null,
                at: data.starts_at,
                to: calendar(data.appointment_id),
            };
        case 'export_ready':
            return { icon: 'data', title: 'bell.export_ready', day: data.expires_at, to: { name: 'settings.data' } };
        case 'practice_deletion_scheduled':
            return {
                icon: 'warning',
                title: 'bell.practice_deletion_scheduled',
                day: data.deletes_at,
                to: { name: 'settings.data' },
            };
        case 'practice_deletion_cancelled':
            return { icon: 'confirm', title: 'bell.practice_deletion_cancelled', to: { name: 'settings.data' } };
        default:
            return { icon: 'notificationsOn', title: 'bell.unknown', to: null };
    }
}
