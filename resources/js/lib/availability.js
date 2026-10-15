/**
 * Settings → Availability and Settings → Booking (docs/spec/12, "Zakazivanje
 * (9b)"): the astrologer's working week as the form edits it, time off and
 * extra time, and the choices offered for the practice's booking rules. The
 * server keeps the week as wall-clock stretches in one zone and works out the
 * free times from it; nothing here decides what a client is offered.
 */
import { addDays, todayIn } from '@/lib/calendar';
import { toLocalInput } from '@/lib/datetime';

/** ISO weekdays as the API numbers them: Monday (1) to Sunday (7). */
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

/** At most this many stretches on one weekday (the server's limit). */
export const MAX_STRETCHES = 10;

/** A day's hours when it is switched on with none. */
export const DEFAULT_STRETCH = { start: '09:00', end: '17:00' };

/** Time off or extra time, as the API names them. */
export const EXCEPTION_KINDS = ['unavailable', 'available'];

/** The longest stretch of time off or extra time, in days (the server's limit). */
export const MAX_EXCEPTION_DAYS = 366;

const DAY = 1440;
const MINUTE = 60_000;

/* ---------------- Times of day ---------------- */

/** "09:30" → 570; "24:00" → 1440; null for anything that is not a time. */
export function minutesOf(time) {
    const match = /^(\d{2}):(\d{2})/.exec(time ?? '');
    if (!match) return null;

    const minutes = Number(match[1]) * 60 + Number(match[2]);

    return minutes <= DAY && Number(match[2]) < 60 ? minutes : null;
}

/** 570 → "09:30"; 1440 → "24:00". */
export function timeOf(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/**
 * A stretch's end in minutes. A time input cannot show "24:00", so midnight at
 * the end of the day is entered (and shown) as "00:00".
 */
export function endMinutesOf(time) {
    const minutes = minutesOf(time);

    return minutes === 0 ? DAY : minutes;
}

/** A time from the API as a time input takes it: "24:00" → "00:00", seconds dropped. */
export function inputTime(time) {
    const minutes = minutesOf(time);
    if (minutes === null) return '';

    return minutes === DAY ? '00:00' : timeOf(minutes);
}

/* ---------------- The working week ---------------- */

/**
 * The week from the API's rules: one entry per weekday, Monday first, with its
 * stretches in order. A day without stretches is off.
 */
export function weekForm(rules = []) {
    return WEEKDAYS.map((weekday) => {
        const stretches = rules
            .filter((rule) => Number(rule.weekday) === weekday)
            .map((rule) => ({ start: inputTime(rule.start_time), end: inputTime(rule.end_time) }))
            .sort((a, b) => (minutesOf(a.start) ?? 0) - (minutesOf(b.start) ?? 0));

        return { weekday, on: stretches.length > 0, stretches };
    });
}

/** A weekday's name in the viewer's language: 1 → "Monday" (5 January 2026 was a Monday). */
export function weekdayName(weekday, locale, style = 'long') {
    return formatter(locale, { weekday: style, timeZone: 'UTC' }).format(new Date(Date.UTC(2026, 0, 4 + weekday)));
}

/** Whether a week has any working time at all. */
export function hasWorkingTime(week) {
    return week.some((day) => day.on && day.stretches.length > 0);
}

/** The shortest free time "Add hours" still suggests, in minutes. */
const MIN_GAP = 15;

/**
 * The stretch "Add hours" suggests: an hour after the day's last one ends, for
 * up to four hours. When the day already runs to midnight, the first free time
 * earlier in the day instead, ending an hour before the stretch that follows
 * when there is room. Null when no free time of at least 15 minutes is left
 * (or no stretch has an end yet).
 */
export function nextStretch(stretches) {
    if (!stretches.length) return { ...DEFAULT_STRETCH };

    const ends = stretches.map((stretch) => endMinutesOf(stretch.end)).filter((end) => end !== null);
    const last = ends.length ? Math.max(...ends) : null;
    if (last === null) return null;

    if (last < DAY) {
        const start = last + 60 < DAY ? last + 60 : last;

        return { start: timeOf(start), end: inputTime(timeOf(Math.min(start + 240, DAY))) };
    }

    const gap = freeTimes(stretches).find(({ from, until }) => until - from >= MIN_GAP);
    if (!gap) return null;

    const end = gap.until - gap.from >= 120 ? gap.until - 60 : gap.until;

    return { start: timeOf(Math.max(gap.from, end - 240)), end: inputTime(timeOf(end)) };
}

/** The free times of a day between its complete stretches, earliest first, in minutes. */
function freeTimes(stretches) {
    const busy = stretches
        .map((stretch) => ({ start: minutesOf(stretch.start), end: endMinutesOf(stretch.end) }))
        .filter(({ start, end }) => start !== null && end !== null && end > start)
        .sort((a, b) => a.start - b.start);

    const gaps = [];
    let from = 0;
    for (const { start, end } of busy) {
        if (start > from) gaps.push({ from, until: start });
        from = Math.max(from, end);
    }
    if (from < DAY) gaps.push({ from, until: DAY });

    return gaps;
}

/** Whether another stretch fits on a day. */
export function canAddStretch(day) {
    return day.stretches.length < MAX_STRETCHES && nextStretch(day.stretches) !== null;
}

/** A day switched on or off; switching on a day without hours gives it the usual ones. */
export function switchDay(day, on) {
    return {
        ...day,
        on,
        stretches: on && !day.stretches.length ? [{ ...DEFAULT_STRETCH }] : day.stretches,
    };
}

/** A day with one more stretch (unchanged when none fits). */
export function addStretch(day) {
    if (!canAddStretch(day)) return day;

    return { ...day, on: true, stretches: [...day.stretches, nextStretch(day.stretches)] };
}

/** A day without one of its stretches; removing the last one makes it a day off. */
export function removeStretch(day, index) {
    const stretches = day.stretches.filter((_, position) => position !== index);

    return { ...day, on: day.on && stretches.length > 0, stretches };
}

/** The week with Tuesday to Friday working Monday's hours. */
export function copyMondayToWeekdays(week) {
    const monday = week.find((day) => day.weekday === 1);
    if (!monday) return week;

    return week.map((day) =>
        day.weekday >= 2 && day.weekday <= 5
            ? { ...day, on: monday.on, stretches: monday.stretches.map((stretch) => ({ ...stretch })) }
            : day,
    );
}

/** The key a stretch's error is kept under: "weekday.position". */
export function stretchKey(weekday, index) {
    return `${weekday}.${index}`;
}

/** Every stretch that is sent, in the order the payload lists them. */
function workingStretches(week) {
    return week
        .filter((day) => day.on)
        .flatMap((day) => day.stretches.map((stretch, index) => ({ weekday: day.weekday, index, stretch })));
}

/**
 * Problems the form can see before saving, as { "weekday.position": code } with
 * codes `required`, `endBeforeStart` and `overlap`, and { weekday: 'tooMany' }
 * for a day over the limit. Stretches that only touch (13:00 and 13:00) are fine.
 */
export function validateWeek(week) {
    const errors = {};

    for (const day of week) {
        if (!day.on) continue;
        if (day.stretches.length > MAX_STRETCHES) errors[day.weekday] = 'tooMany';

        const spans = day.stretches.map((stretch) => ({
            start: minutesOf(stretch.start),
            end: endMinutesOf(stretch.end),
        }));

        spans.forEach((span, index) => {
            const key = stretchKey(day.weekday, index);

            if (span.start === null || span.end === null) {
                errors[key] = 'required';
            } else if (span.end <= span.start) {
                errors[key] = 'endBeforeStart';
            } else if (
                spans.some(
                    (other, position) =>
                        position !== index &&
                        other.start !== null &&
                        other.end !== null &&
                        other.end > other.start &&
                        span.start < other.end &&
                        other.start < span.end,
                )
            ) {
                errors[key] = 'overlap';
            }
        });
    }

    return errors;
}

/** What PUT /availability takes: the zone and every working stretch ("24:00" for midnight). */
export function weekPayload(week, timezone) {
    return {
        timezone,
        rules: workingStretches(week).map(({ weekday, stretch }) => ({
            weekday,
            start_time: timeOf(minutesOf(stretch.start)),
            end_time: timeOf(endMinutesOf(stretch.end)),
        })),
    };
}

/**
 * The server's validation errors put back on the form: `rules.3.end_time` (the
 * fourth stretch sent) lands on that stretch's row; anything else (`timezone`,
 * `rules`) stays as it is.
 */
export function weekErrors(serverErrors, week) {
    const positions = workingStretches(week);
    const stretches = {};
    const other = {};

    for (const [field, message] of Object.entries(serverErrors ?? {})) {
        const match = /^rules\.(\d+)(\.|$)/.exec(field);
        const position = match ? positions[Number(match[1])] : null;

        if (position) {
            const key = stretchKey(position.weekday, position.index);
            stretches[key] ??= message;
        } else {
            other[field] ??= message;
        }
    }

    return { stretches, other };
}

/* ---------------- Time off and extra time ---------------- */

/** A blank exception: time off for the whole of today, in the week's zone. */
export function exceptionForm(timeZone, now = new Date()) {
    const today = todayIn(timeZone || 'UTC', now);

    return {
        kind: 'unavailable',
        all_day: true,
        first_day: today,
        last_day: today,
        starts_local: `${today}T09:00`,
        ends_local: `${today}T17:00`,
        timezone: timeZone || 'UTC',
        note: '',
    };
}

/**
 * The form switched between whole days and times, carrying the dates across:
 * the first and last day become the days of the start and end, and back.
 */
export function withWholeDays(form, allDay) {
    if (allDay === Boolean(form.all_day)) return form;

    if (allDay) {
        const first = form.starts_local?.slice(0, 10) || form.first_day;
        const last = form.ends_local?.slice(0, 10) || form.last_day;

        return { ...form, all_day: true, first_day: first, last_day: last < first ? first : last };
    }

    const time = (local, fallback) => (/T\d{2}:\d{2}$/.test(local ?? '') ? local.slice(11) : fallback);

    return {
        ...form,
        all_day: false,
        starts_local: `${form.first_day}T${time(form.starts_local, '09:00')}`,
        ends_local: `${form.last_day || form.first_day}T${time(form.ends_local, '17:00')}`,
    };
}

/** Milliseconds of a wall-clock value ("2026-10-20T09:00"), read as if it were UTC. */
function wallMillis(local) {
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/.exec(local ?? '');
    if (!match) return null;

    const [, year, month, day, hours = '0', minutes = '0'] = match;

    return Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hours), Number(minutes));
}

/** `now` on the wall clock of a zone, in the milliseconds wallMillis() gives; null for a zone Intl does not know. */
function wallNow(timeZone, now) {
    try {
        return wallMillis(toLocalInput(now, timeZone || 'UTC'));
    } catch {
        return null;
    }
}

/**
 * Problems the form can see before sending, keyed like the server's fields
 * (`starts_at`, `ends_at`): `required`, `lastBeforeFirst`, `endBeforeStart`,
 * `tooLong`, and `ended` for a period that is already over in the form's zone
 * (it would never be listed or count). The server checks again, on the zone's
 * real clock.
 */
export function validateException(form, now = new Date()) {
    const [start, end] = form.all_day ? [form.first_day, form.last_day] : [form.starts_local, form.ends_local];
    const from = wallMillis(start);
    const until = wallMillis(end);
    const errors = {};

    if (from === null) errors.starts_at = 'required';
    if (until === null) errors.ends_at = 'required';
    if (from === null || until === null) return errors;

    // Whole days end at midnight after the last one.
    const ends = form.all_day ? until + DAY * MINUTE : until;
    const current = wallNow(form.timezone, now);

    if (form.all_day && until < from) {
        errors.ends_at = 'lastBeforeFirst';
    } else if (form.all_day && (until - from) / (DAY * MINUTE) + 1 > MAX_EXCEPTION_DAYS) {
        errors.ends_at = 'tooLong';
    } else if (!form.all_day && until <= from) {
        errors.ends_at = 'endBeforeStart';
    } else if (!form.all_day && until - from > MAX_EXCEPTION_DAYS * DAY * MINUTE) {
        errors.ends_at = 'tooLong';
    } else if (current !== null && ends <= current) {
        errors.ends_at = 'ended';
    }

    return errors;
}

/**
 * What POST /availability/exceptions takes: whole days as their first and last
 * date (inclusive), otherwise wall-clock times, both in the chosen zone.
 */
export function exceptionPayload(form) {
    const note = (form.note ?? '').trim();

    return {
        kind: form.kind,
        all_day: Boolean(form.all_day),
        starts_at: form.all_day ? form.first_day : form.starts_local,
        ends_at: form.all_day ? form.last_day : form.ends_local,
        timezone: form.timezone,
        note: note === '' ? null : note,
    };
}

/** Exceptions that have not ended yet, soonest first. */
export function upcomingExceptions(exceptions, now = new Date()) {
    return exceptions
        .filter((exception) => new Date(exception.ends_at).getTime() > now.getTime())
        .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime() || a.id - b.id);
}

function formatter(locale, options) {
    try {
        return new Intl.DateTimeFormat(locale, options);
    } catch {
        return new Intl.DateTimeFormat(undefined, options);
    }
}

function range(format, from, until) {
    if (typeof format.formatRange === 'function') return format.formatRange(from, until);

    return `${format.format(from)} – ${format.format(until)}`;
}

/** The first and last day (inclusive) of a whole-days exception, on its own zone's calendar. */
export function exceptionDays(exception) {
    const zone = exception.timezone || 'UTC';
    const first =
        exception.starts_local?.slice(0, 10) || toLocalInput(new Date(exception.starts_at), zone).slice(0, 10);
    const last =
        exception.ends_local?.slice(0, 10) || addDays(toLocalInput(new Date(exception.ends_at), zone).slice(0, 10), -1);

    return { first, last };
}

/**
 * An exception's period in words, on the clock of the zone it was entered in:
 * whole days as dates ("Sat, 24 Oct – Mon, 26 Oct 2026"), otherwise the times
 * ("24 Oct 2026, 09:00 – 13:00"). Intl does the zone's arithmetic, so a period
 * across a change of the clocks still reads 09:00 to 09:00.
 */
export function exceptionPeriod(exception, locale) {
    if (exception.all_day) {
        const { first, last } = exceptionDays(exception);
        const format = formatter(locale, {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        });
        const from = new Date(wallMillis(first));
        const until = new Date(wallMillis(last));

        return first === last ? format.format(from) : range(format, from, until);
    }

    const format = formatter(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: exception.timezone || 'UTC',
    });

    return range(format, new Date(exception.starts_at), new Date(exception.ends_at));
}

/* ---------------- Booking rules ---------------- */

/** The rules a practice starts with (BookingPolicy::DEFAULTS). */
export const POLICY_DEFAULTS = {
    enabled: false,
    requires_confirmation: false,
    min_notice_minutes: 1440,
    max_days_ahead: 60,
    slot_step_minutes: 30,
    buffer_before_minutes: 0,
    buffer_after_minutes: 15,
    client_reschedule_until_hours: 24,
    client_cancel_until_hours: 24,
    client_note: '',
};

/** Earliest booking, in minutes from now: none, 1, 2, 4, 12, 24, 48, 72 hours, a week. */
export const MIN_NOTICE_CHOICES = [0, 60, 120, 240, 720, 1440, 2880, 4320, 10080];

/** How far ahead clients can book, in days. */
export const MAX_DAYS_CHOICES = [7, 14, 30, 60, 90, 180, 365];

/** The grid start times sit on, in minutes. */
export const SLOT_STEP_CHOICES = [15, 20, 30, 45, 60, 90, 120];

/** Free time kept before or after each appointment, in minutes. */
export const BUFFER_CHOICES = [0, 5, 10, 15, 20, 30, 45, 60];

/**
 * Until when a client may move or cancel, in hours before the start: null = not
 * in the portal at all, 0 = until it starts, up to a week.
 */
export const CHANGE_UNTIL_CHOICES = [null, 0, 1, 2, 4, 12, 24, 48, 72, 168];

/** A list of choices that keeps a value saved elsewhere (the API takes more than the list). */
export function choicesWith(choices, current) {
    if (current === undefined || choices.includes(current)) return [...choices];
    if (current !== null && !Number.isInteger(current)) return [...choices];

    return [...choices, current].sort((a, b) => (a === null ? -1 : b === null ? 1 : a - b));
}

/** A length of time in its largest whole unit, for wording: { unit, count }. */
export function durationParts(minutes) {
    if (minutes > 0 && minutes % 10080 === 0) return { unit: 'weeks', count: minutes / 10080 };
    if (minutes > 0 && minutes % 1440 === 0) return { unit: 'days', count: minutes / 1440 };
    if (minutes > 0 && minutes % 60 === 0) return { unit: 'hours', count: minutes / 60 };

    return { unit: 'minutes', count: minutes };
}

/** Rules where null means something (the client may not do it in the portal). */
const NULLABLE_RULES = ['client_reschedule_until_hours', 'client_cancel_until_hours'];

/** The booking rules as the settings form edits them; anything missing takes its default. */
export function policyForm(policy) {
    return Object.fromEntries(
        Object.entries(POLICY_DEFAULTS).map(([key, fallback]) => {
            const value = policy?.[key];
            const missing = value === undefined || (value === null && !NULLABLE_RULES.includes(key));

            return [key, missing ? fallback : value];
        }),
    );
}

/** What PUT /booking-policy takes from the form. */
export function policyPayload(form) {
    const note = (form.client_note ?? '').trim();

    return {
        enabled: Boolean(form.enabled),
        requires_confirmation: Boolean(form.requires_confirmation),
        min_notice_minutes: form.min_notice_minutes,
        max_days_ahead: form.max_days_ahead,
        slot_step_minutes: form.slot_step_minutes,
        buffer_before_minutes: form.buffer_before_minutes,
        buffer_after_minutes: form.buffer_after_minutes,
        client_reschedule_until_hours: form.client_reschedule_until_hours,
        client_cancel_until_hours: form.client_cancel_until_hours,
        client_note: note === '' ? null : note,
    };
}

/**
 * Whether clients can actually book, from the saved rules: `off` (booking is
 * switched off), `incomplete` (on, but `missing` lists what keeps every time
 * from being offered — `astrologer`, `services`, `hours`) or `open`.
 */
export function bookingState(policy) {
    if (!policy?.enabled) return { state: 'off', missing: [] };

    const missing = [];
    if (!policy.astrologer) missing.push('astrologer');
    if (!(policy.bookable_services > 0)) missing.push('services');
    if (!policy.has_availability) missing.push('hours');

    return { state: missing.length ? 'incomplete' : 'open', missing };
}
