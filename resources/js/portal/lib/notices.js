/**
 * The client's notices about their appointments (Portal → Profile → Notifications;
 * docs/spec/12, user's decision of 18 Nov 2026): each one by email, push to their
 * devices, both or neither — the confirmations of their own steps included. The
 * invitation and the sign-in codes always come by email. The kinds mirror
 * ClientNotificationPreferences::KINDS.
 */

export const CLIENT_CHANNELS = ['mail', 'push'];

export const CLIENT_NOTICE_GROUPS = [
    { key: 'yours', kinds: ['booked', 'requested', 'moved_by_you', 'move_requested', 'cancelled_by_you'] },
    { key: 'practice', kinds: ['confirmed', 'declined', 'move_confirmed', 'move_declined', 'moved', 'cancelled'] },
    { key: 'reminders', kinds: ['reminder'] },
];

export const CLIENT_ALWAYS_BY_EMAIL = ['invitation', 'signIn'];

/** A copy of the stored choice the screen can change: every notice and channel, on unless stored off. */
export function noticesForm(stored) {
    const form = {};

    CLIENT_NOTICE_GROUPS.forEach((group) =>
        group.kinds.forEach((kind) => {
            form[kind] = {};
            CLIENT_CHANNELS.forEach((channel) => {
                form[kind][channel] = stored?.[kind]?.[channel] ?? true;
            });
        }),
    );

    return form;
}
