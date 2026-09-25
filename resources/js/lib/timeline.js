/**
 * How a timeline entry reads. Returns translation keys and parameters, not
 * text, so the wording stays in the locale files.
 *
 * - tone: the dot colour (t-consultation, t-note, t-file, t-chart, t-profile)
 * - title: [key, params]
 * - body: text the astrologer wrote (topics, an excerpt), shown as is
 * - fields: changed field names, each translated under timeline.fields.*
 * - to: where the entry leads — a route, or a tab of the client profile
 * - moved / wasAt: moments (UTC) the component formats in the viewer's zone
 * - task: the task's deadline, priority and status, worded by the component
 * - money / day: a payment's amount (the component formats it into the title
 *   as `amount`) and the day it arrived, shown instead of a time
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
        case 'appointment':
            return {
                tone: 'appointment',
                title: [`timeline.appointment.${meta.status}`, { title: meta.service ?? '' }],
                titled: Boolean(meta.service),
                // A cancelled appointment is not coming up, even when its time is.
                cancelled: meta.status === 'cancelled',
                to: appointmentRoute(event.subject.id),
            };
        case 'appointment_rescheduled':
            return {
                tone: 'appointment',
                title: ['timeline.appointmentMoved', {}],
                moved: { from: meta.from, to: meta.to },
                to: appointmentRoute(event.subject.id),
            };
        case 'appointment_cancelled':
            return {
                tone: 'appointment',
                title: ['timeline.appointmentCancelled', {}],
                body: meta.reason ?? null,
                wasAt: meta.starts_at ?? null,
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
            return { tone: 'profile', title: ['timeline.profileUpdated', {}], fields: meta.fields ?? [] };
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
