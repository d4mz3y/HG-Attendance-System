/**
 * Keep human-facing clock displays consistent throughout the portal.
 * Storage and API input remain 24-hour HH:mm values; this is presentation
 * only, so schedule/attendance calculations are unaffected.
 */
export function formatTime(value, fallback = '—') {
    if (!value) return fallback;

    const raw = String(value).trim();
    const twelveHourMatch = raw.match(/^(\d{1,2}):(\d{2})\s*([AaPp][Mm])$/);
    if (twelveHourMatch) {
        const hours = Number(twelveHourMatch[1]);
        const minutes = Number(twelveHourMatch[2]);
        if (hours >= 1 && hours <= 12 && minutes >= 0 && minutes <= 59) {
            return `${hours}:${String(minutes).padStart(2, '0')} ${twelveHourMatch[3].toUpperCase()}`;
        }
    }

    const directMatch = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if (directMatch) {
        const hours = Number(directMatch[1]);
        const minutes = Number(directMatch[2]);
        if (hours >= 0 && hours <= 23 && minutes >= 0 && minutes <= 59) {
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const displayHour = hours % 12 || 12;
            return `${displayHour}:${String(minutes).padStart(2, '0')} ${suffix}`;
        }
    }

    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return raw || fallback;

    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

export function formatDateTime(value, fallback = '—') {
    if (!value) return fallback;

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value) || fallback;

    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}
