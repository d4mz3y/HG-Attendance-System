import React, { useEffect, useState } from 'react';
import TimePicker from './TimePicker';

function splitDateTime(value) {
    const raw = String(value ?? '');
    const match = raw.match(/^(\d{4}-\d{2}-\d{2})T(\d{1,2}:\d{2})/);
    return {
        date: match?.[1] ?? '',
        time: match?.[2] ?? '',
    };
}

/**
 * Date plus the same AM/PM picker used by schedules. This replaces browser
 * datetime-local widgets, which frequently force a 24-hour display.
 */
export default function DateTimePicker({
    value,
    onChange,
    name,
    id,
    disabled = false,
    required = false,
    className = '',
    ariaLabel = 'Date and time',
}) {
    // As with TimePicker, preserve a partially selected date/time until all
    // parts are present and can be sent to the API as one local timestamp.
    const [parts, setParts] = useState(() => splitDateTime(value));

    useEffect(() => {
        setParts(splitDateTime(value));
    }, [value]);

    const emit = (next) => {
        setParts(next);
        const nextValue = next.date && next.time ? `${next.date}T${next.time}` : '';
        onChange?.({ target: { name, value: nextValue } });
    };

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`}>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
            <input
                id={id}
                type="date"
                aria-label={`${ariaLabel} date`}
                value={parts.date}
                onChange={(event) => emit({ ...parts, date: event.target.value })}
                disabled={disabled}
                required={required}
                className="min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
            />
            <TimePicker
                value={parts.time}
                onChange={(event) => emit({ ...parts, time: event.target.value })}
                disabled={disabled}
                required={required}
                ariaLabel={`${ariaLabel} time`}
            />
        </div>
    );
}
