/**
 * Display helpers for astrological data. All take the current locale so
 * dates and numbers follow the viewer's conventions.
 */

/** 45.25167 → "45°15′N"; longitude uses E/W. */
export function formatCoordinate(value, axis) {
    if (value === null || value === undefined) return '';

    const hemisphere = axis === 'lat' ? (value >= 0 ? 'N' : 'S') : value >= 0 ? 'E' : 'W';
    const absolute = Math.abs(value);
    let degrees = Math.floor(absolute);
    let minutes = Math.round((absolute - degrees) * 60);

    if (minutes === 60) {
        degrees += 1;
        minutes = 0;
    }

    return `${degrees}°${String(minutes).padStart(2, '0')}′${hemisphere}`;
}

/** A calendar date ("1985-07-15") without any time-zone shift. */
export function formatDate(isoDate, locale, style = 'long') {
    if (!isoDate) return '';
    const [year, month, day] = isoDate.split('-').map(Number);

    return new Intl.DateTimeFormat(locale, { dateStyle: style, timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, month - 1, day)),
    );
}

/** "3 days ago", "in 2 hours" … for an ISO timestamp. */
export function formatRelative(isoTimestamp, locale, now = new Date()) {
    if (!isoTimestamp) return '';

    const seconds = (new Date(isoTimestamp).getTime() - now.getTime()) / 1000;
    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

export function initials(name) {
    return (name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}
