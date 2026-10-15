/**
 * How a timeline entry reads. Returns translation keys and parameters, not
 * text, so the wording stays in the locale files.
 *
 * - tone: the dot colour (t-consultation, t-note, t-file, t-chart, t-profile)
 * - title: [key, params]
 * - body: text the astrologer wrote (topics, an excerpt), shown as is
 * - fields: changed field names, each translated under timeline.fields.*
 * - to: where the entry leads — a route, or a tab of the client profile
 * - detail: a second line, [key, params] (e.g. that the client booked it in the portal)
 * - moved / wasAt / at: moments (UTC) the component formats in the viewer's zone
 * - task: the task's deadline, priority and status, worded by the component
 * - money / day: a payment's amount (the component formats it into the title
 *   as `amount`) and the day it arrived, shown instead of a time
 *
 * What the client did in the portal says so (Phase 9a, 9b): `source: 'portal'`
 * on a log entry, `booking_source: 'portal'` on an appointment.
 */
export function describeEvent(event) {
    const meta = event.metadata ?? {};

    switch (event.type) {
        case 'consultation':
            return {
                tone: 'consultation',
                // Without a title, the consultation goes by its service (the summary holds either).
                title: [`timeline.consultation.${meta.status}`, { title: meta.title ?? meta.service ?? '' }],
                titled: Boolean(meta.title || meta.service),
                body: meta.topics ?? null,
                to: { name: 'consultations.show', params: { id: event.subject.id } },
            };
        case 'appointment': {
            // Booked by the client in the portal (Phase 9b): a request until it is answered, then
            // "booked in the portal" while it is ahead; once held or ended, a second line says where it came from.
            const portal = meta.booking_source === 'portal';
            const portalTitle = portal && meta.status === 'scheduled';

            return {
                tone: 'appointment',
                title: [
                    portalTitle ? 'timeline.bookedInPortal' : `timeline.appointment.${meta.status}`,
                    { title: meta.service ?? '' },
                ],
                titled: Boolean(meta.service),
                detail: portal && !portalTitle && meta.status !== 'requested' ? ['timeline.bookedInPortal', {}] : null,
                // A cancelled appointment is not coming up, even when its time is.
                cancelled: meta.status === 'cancelled',
                to: appointmentRoute(event.subject.id),
            };
        }
        case 'appointment_rescheduled':
            return {
                tone: 'appointment',
                title: [
                    meta.source === 'portal' ? 'timeline.appointmentMovedByClient' : 'timeline.appointmentMoved',
                    {},
                ],
                moved: { from: meta.from, to: meta.to },
                to: appointmentRoute(event.subject.id),
            };
        case 'appointment_cancelled':
            return {
                tone: 'appointment',
                // Declined request: the reason went to the client. Cancelled in the portal: the client's own message.
                title: [
                    meta.declined
                        ? 'timeline.requestDeclined'
                        : meta.source === 'portal'
                          ? 'timeline.appointmentCancelledByClient'
                          : 'timeline.appointmentCancelled',
                    {},
                ],
                body: meta.reason ?? null,
                detail: meta.declined && meta.reason ? ['timeline.declineSent', {}] : null,
                wasAt: meta.starts_at ?? null,
                to: appointmentRoute(event.subject.id),
            };
        case 'appointment_confirmed':
            return {
                tone: 'appointment',
                title: ['timeline.appointmentConfirmed', {}],
                at: meta.starts_at ?? null,
                to: appointmentRoute(event.subject.id),
            };
        case 'task':
            return {
                tone: 'task',
                title: ['timeline.task', { title: meta.title ?? event.summary ?? '' }],
                task: meta,
                to: { tab: 'tasks' },
            };
        case 'task_completed':
            return {
                tone: 'task',
                title: ['timeline.taskCompleted', { title: meta.title ?? event.summary ?? '' }],
                to: { tab: 'tasks' },
            };
        case 'payment': {
            // Paid for an appointment and not yet for its consultation: a deposit.
            const deposit = meta.appointment_id && !meta.consultation_id;

            return {
                tone: 'payment',
                title: [
                    meta.kind === 'refund' ? 'timeline.refund' : deposit ? 'timeline.deposit' : 'timeline.payment',
                    {},
                ],
                money: { amount: meta.amount, currency: meta.currency },
                day: meta.paid_on,
                body: event.summary ?? null,
                to: meta.consultation_id
                    ? { name: 'consultations.show', params: { id: meta.consultation_id } }
                    : meta.appointment_id
                      ? appointmentRoute(meta.appointment_id)
                      : null,
            };
        }
        case 'note':
            return {
                tone: 'note',
                title: meta.title ? ['timeline.noteTitled', { title: meta.title }] : ['timeline.note', {}],
                body: meta.excerpt ?? null,
                private: event.visibility === 'private',
                to: { tab: 'notes' },
            };
        case 'file':
            return {
                tone: 'file',
                title: [meta.kind === 'link' ? 'timeline.link' : 'timeline.file', { name: meta.name }],
                body: null,
                private: event.visibility === 'private',
                to: meta.consultation_id
                    ? { name: 'consultations.show', params: { id: meta.consultation_id } }
                    : { tab: 'files' },
            };
        case 'chart_calculated':
            return {
                tone: 'chart',
                title: [meta.recalculated ? 'timeline.chartRecalculated' : 'timeline.chartCalculated', {}],
                body: null,
                chart: meta,
                to: { tab: 'chart' },
            };
        case 'birth_details_updated':
            return {
                tone: 'chart',
                title: [meta.added ? 'timeline.birthAdded' : 'timeline.birthUpdated', {}],
                fields: meta.fields ?? [],
                to: { tab: 'chart' },
            };
        case 'client_updated':
            return {
                tone: 'profile',
                // The client changed their own phone number in the portal (Phase 9a).
                title: [meta.source === 'portal' ? 'timeline.profileUpdatedByClient' : 'timeline.profileUpdated', {}],
                fields: meta.fields ?? [],
            };
        case 'client_archived':
            return { tone: 'profile', title: ['timeline.archived', {}] };
        case 'client_restored':
            return { tone: 'profile', title: ['timeline.restored', {}] };
        case 'client_created':
            return { tone: 'profile', title: ['timeline.created', {}] };
        default:
            return { tone: 'profile', title: ['timeline.unknown', {}] };
    }
}

/** Filters offered above the timeline (docs/spec/02). */
export const TIMELINE_FILTERS = [
    'all',
    'appointments',
    'consultations',
    'notes',
    'files',
    'payments',
    'tasks',
    'charts',
];

/** An appointment opens in the calendar, on its own day, with its details. */
function appointmentRoute(id) {
    return { name: 'calendar', query: { appointment: id } };
}
