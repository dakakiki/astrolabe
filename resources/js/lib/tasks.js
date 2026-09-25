import { addDays, daysBetween } from '@/lib/calendar';
import { toLocalInput } from '@/lib/datetime';

/**
 * Tasks and follow-ups (docs/spec/02). The server says where an open task
 * stands on the viewer's calendar (`due_state`: overdue, today, upcoming);
 * this words the deadline and shapes requests. Days are keys ("2026-10-05")
 * in the viewer's zone, as in the calendar.
 */

export const PRIORITIES = ['high', 'normal', 'low'];

/** Tabs of a task list, in order. */
export const TASK_VIEWS = ['open', 'overdue', 'today', 'done'];

/** Days from a consultation to its follow-up's suggested deadline. */
export const FOLLOW_UP_DAYS = 7;

/** Query parameters for a tab of the list. */
export function viewParams(view) {
    switch (view) {
        case 'overdue':
            return { due: 'overdue' };
        case 'today':
            return { due: 'today' };
        case 'done':
            return { status: 'done' };
        case 'all':
            return { status: 'all' };
        default:
            return { status: 'open' };
    }
}

/**
 * The deadline on the viewer's wall clock: as entered, or — for a time set in
 * another zone — converted to the viewer's. Null without a due date.
 */
export function dueMoment(task, timeZone) {
    if (!task.due_date) return null;

    if (task.due_time && task.due_at && task.timezone && timeZone && task.timezone !== timeZone) {
        const local = toLocalInput(new Date(task.due_at), timeZone);

        return { date: local.slice(0, 10), time: local.slice(11, 16), converted: true };
    }

    return { date: task.due_date, time: task.due_time ?? null, converted: false };
}

/**
 * How the deadline reads, relative to today: `{ key, params, tone }` for the
 * translation and the colour (danger when overdue, warn when due today). Dates
 * in `params.date` are day keys, formatted by the caller. Null without a due date.
 */
export function describeDue(task, today, timeZone) {
    const due = dueMoment(task, timeZone);
    if (!due) return null;

    const at = due.time ? 'At' : '';
    const params = { date: due.date, time: due.time };

    if (task.status === 'done') {
        return { key: `tasks.due.on${at}`, params, tone: '' };
    }

    if (task.due_state === 'overdue') {
        const days = due.date < today ? daysBetween(due.date, today).length - 1 : 0;

        return days === 0
            ? { key: 'tasks.due.overdueSince', params, tone: 'danger' }
            : { key: 'tasks.due.overdueDays', params: { ...params, count: days }, tone: 'danger' };
    }

    if (due.date <= today) {
        return { key: `tasks.due.today${at}`, params, tone: 'warn' };
    }

    if (due.date === addDays(today, 1)) {
        return { key: `tasks.due.tomorrow${at}`, params, tone: '' };
    }

    return { key: `tasks.due.on${at}`, params, tone: '' };
}

/** A new follow-up's suggested deadline: a week after today. */
export function followUpDate(today, days = FOLLOW_UP_DAYS) {
    return addDays(today, days);
}

/**
 * Form values → request body. Empty fields are sent as null; a time without a
 * day is dropped, so clearing the day clears the deadline.
 */
export function taskPayload(data) {
    const dueDate = data.due_date || null;

    return {
        title: (data.title ?? '').trim(),
        description: (data.description ?? '').trim() || null,
        priority: data.priority || 'normal',
        remind: data.remind !== false,
        due_date: dueDate,
        due_time: dueDate ? data.due_time || null : null,
    };
}
