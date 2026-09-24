/**
 * How a timeline entry reads. Returns translation keys and parameters, not
 * text, so the wording stays in the locale files.
 *
 * - tone: the dot colour (t-consultation, t-note, t-file, t-chart, t-profile)
 * - title: [key, params]
 * - body: text the astrologer wrote (topics, an excerpt), shown as is
 * - fields: changed field names, each translated under timeline.fields.*
 * - to: where the entry leads — a route, or a tab of the client profile
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

/** Filters offered above the timeline (docs/spec/02); payments and tasks arrive with their phases. */
export const TIMELINE_FILTERS = ['all', 'consultations', 'notes', 'files', 'charts'];
