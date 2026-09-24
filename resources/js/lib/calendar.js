import { toLocalInput } from '@/lib/datetime';

/**
 * Calendar arithmetic (docs/spec/10). Days are keys like "2026-10-05" in the
 * astrologer's own zone; appointments arrive as UTC moments and are placed on
 * that zone's wall clock. Day keys are computed at UTC midnight, so the
 * browser's own zone never shifts a day.
 */

export const VIEWS = ['day', 'week', 'month', 'agenda'];

/** How many days the agenda shows from its date. */
export const AGENDA_DAYS = 30;

const toDate = (key) => new Date(`${key}T00:00:00Z`);
const toKey = (date) => date.toISOString().slice(0, 10);
const pad = (value) => String(value).padStart(2, '0');

export function addDays(key, days) {
    const date = toDate(key);
    date.setUTCDate(date.getUTCDate() + days);

    return toKey(date);
}

/** Monday 0 … Sunday 6. */
export function weekday(key) {
    return (toDate(key).getUTCDay() + 6) % 7;
}

export function startOfWeek(key) {
    return addDays(key, -weekday(key));
}

export function startOfMonth(key) {
    return `${key.slice(0, 8)}01`;
}

export function addMonths(key, months) {
    const date = toDate(startOfMonth(key));
    date.setUTCMonth(date.getUTCMonth() + months);

    return toKey(date);
}

/** Every day from `from` to `to`, both included. */
export function daysBetween(from, to) {
    const days = [];
    for (let day = from; day <= to; day = addDays(day, 1)) days.push(day);

    return days;
}

/** The days a view shows around a date: a month is six whole weeks. */
export function viewRange(view, key) {
    switch (view) {
        case 'day':
            return { from: key, to: key };
        case 'week': {
            const from = startOfWeek(key);
            return { from, to: addDays(from, 6) };
        }
        case 'month': {
            const from = startOfWeek(startOfMonth(key));
            return { from, to: addDays(from, 41) };
        }
        default:
            return { from: key, to: addDays(key, AGENDA_DAYS - 1) };
    }
}

/** The date one step (+1 / -1) further in a view. */
export function shiftDate(view, key, step) {
    switch (view) {
        case 'day':
            return addDays(key, step);
        case 'week':
            return addDays(key, 7 * step);
        case 'month':
            return addMonths(key, step);
        default:
            return addDays(key, AGENDA_DAYS * step);
    }
}

/** A UTC moment on a zone's wall clock: { date: "2026-10-05", minutes: 900 }. */
export function wallClock(isoTimestamp, timeZone) {
    const local = toLocalInput(new Date(isoTimestamp), timeZone);

    return { date: local.slice(0, 10), minutes: Number(local.slice(11, 13)) * 60 + Number(local.slice(14, 16)) };
}

/** The day key "now" falls on in a zone. */
export function todayIn(timeZone, now = new Date()) {
    return toLocalInput(now, timeZone).slice(0, 10);
}

/**
 * The appointments of one day, placed for a time grid: start and end in
 * minutes from midnight (cut at the day's edges) and a column, so that
 * appointments at the same time stand side by side. `columns` is how many
 * columns their group needs.
 */
export function layoutDay(appointments, dayKey, timeZone) {
    const items = appointments
        .map((appointment) => {
            const start = wallClock(appointment.starts_at, timeZone);
            const end = wallClock(appointment.ends_at, timeZone);

            if (
                start.date > dayKey ||
                end.date < dayKey ||
                (end.date === dayKey && end.minutes === 0 && start.date < dayKey)
            ) {
                return null;
            }

            const from = start.date < dayKey ? 0 : start.minutes;
            const to = end.date > dayKey ? 1440 : end.minutes;

            return { appointment, start: from, end: Math.max(to, from + 15) };
        })
        .filter(Boolean)
        .sort((a, b) => a.start - b.start || b.end - a.end);

    const placed = [];
    let group = [];
    let groupEnd = -1;

    const closeGroup = () => {
        const columns = Math.max(...group.map((item) => item.column)) + 1;
        group.forEach((item) => placed.push({ ...item, columns }));
        group = [];
        groupEnd = -1;
    };

    for (const item of items) {
        if (group.length && item.start >= groupEnd) closeGroup();

        const taken = group.filter((other) => other.end > item.start).map((other) => other.column);
        let column = 0;
        while (taken.includes(column)) column++;

        group.push({ ...item, column });
        groupEnd = Math.max(groupEnd, item.end);
    }
    if (group.length) closeGroup();

    return placed;
}

/**
 * Ids of appointments that overlap another one of the same astrologer, for the
 * conflict marker. Cancelled appointments free their time and never conflict.
 */
export function conflictingIds(appointments) {
    const active = appointments.filter((appointment) => appointment.status !== 'cancelled');
    const ids = new Set();

    for (let i = 0; i < active.length; i++) {
        for (let j = i + 1; j < active.length; j++) {
            const a = active[i];
            const b = active[j];
            const sameAstrologer = (a.assigned_user?.id ?? null) === (b.assigned_user?.id ?? null);

            // ISO timestamps in UTC ("…Z") compare correctly as strings.
            if (sameAstrologer && a.starts_at < b.ends_at && b.starts_at < a.ends_at) {
                ids.add(a.id);
                ids.add(b.id);
            }
        }
    }

    return ids;
}

/** Appointments grouped by the day they start on, in order, for the agenda. */
export function groupByDay(appointments, timeZone) {
    const days = new Map();

    [...appointments]
        .sort((a, b) => (a.starts_at < b.starts_at ? -1 : a.starts_at > b.starts_at ? 1 : 0))
        .forEach((appointment) => {
            const { date } = wallClock(appointment.starts_at, timeZone);
            if (!days.has(date)) days.set(date, []);
            days.get(date).push(appointment);
        });

    return [...days].map(([date, items]) => ({ date, appointments: items }));
}

/** The hours a time grid shows: 08–20 by default, widened to fit what is on it. */
export function visibleHours(items, defaults = [8, 20]) {
    let [from, to] = defaults;

    items.forEach((item) => {
        from = Math.min(from, Math.floor(item.start / 60));
        to = Math.max(to, Math.ceil(item.end / 60));
    });

    return [from, Math.min(to, 24)];
}

/** The local start ("2026-10-05T15:30") for a click at `minutes` on a day, snapped to the step. */
export function slotStart(dayKey, minutes, step = 30) {
    const snapped = Math.max(0, Math.min(1440 - step, Math.floor(minutes / step) * step));

    return `${dayKey}T${pad(Math.floor(snapped / 60))}:${pad(snapped % 60)}`;
}
