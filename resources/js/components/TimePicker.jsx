import React, { useEffect, useState } from 'react';

const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 60 }, (_, index) => String(index).padStart(2, '0'));

function partsFor(value) {
    const match = String(value ?? '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) return { hour: '', minute: '', period: 'AM' };

    const hours = Number(match[1]);
    const minute = Number(match[2]);
    if (hours < 0 || hours > 23 || minute < 0 || minute > 59) {
        return { hour: '', minute: '', period: 'AM' };
    }

    return {
        hour: String(hours % 12 || 12),
        minute: String(minute).padStart(2, '0'),
        period: hours >= 12 ? 'PM' : 'AM',
    };
}

function toTwentyFourHour({ hour, minute, period }) {
    if (!hour || minute === '') return '';

    let hours = Number(hour) % 12;
    if (period === 'PM') hours += 12;

    return `${String(hours).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

/**
 * A deliberately 12-hour time control. The API continues to receive the
 * existing HH:mm value, but people never need to interpret a 24-hour clock.
 */
export default function TimePicker({
    value,
    onChange,
    name,
    id,
    disabled = false,
    required = false,
    allowEmpty = false,
    className = '',
    selectClassName = '',
    ariaLabel = 'Time',
}) {
    // Keep an in-progress selection locally. A blank picker must let someone
    // choose (for example) "3" before they choose "15"; emitting an empty
    // HH:mm value for that first click must not immediately erase the draft.
    const [parts, setParts] = useState(() => partsFor(value));

    useEffect(() => {
        setParts(partsFor(value));
    }, [value]);
    const baseSelectClass = `min-w-0 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 ${selectClassName}`;

    const emit = (next) => {
        setParts(next);
        onChange?.({ target: { name, value: toTwentyFourHour(next) } });
    };

    const changeHour = (event) => emit({ ...parts, hour: event.target.value });
    const changeMinute = (event) => emit({ ...parts, minute: event.target.value });
    const changePeriod = (event) => emit({ ...parts, period: event.target.value });

    return (
        <div className={`flex items-center gap-1.5 ${className}`} role="group" aria-label={ariaLabel}>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
            <select
                id={id}
                aria-label={`${ariaLabel} hour`}
                value={parts.hour}
                onChange={changeHour}
                disabled={disabled}
                required={required}
                className={baseSelectClass}
            >
                {allowEmpty && <option value="">Hour</option>}
                {!allowEmpty && !parts.hour && <option value="">Hour</option>}
                {HOURS.map((hour) => <option key={hour} value={hour}>{hour}</option>)}
            </select>
            <span aria-hidden="true" className="font-semibold text-slate-500 dark:text-slate-400">:</span>
            <select
                aria-label={`${ariaLabel} minute`}
                value={parts.minute}
                onChange={changeMinute}
                disabled={disabled}
                required={required}
                className={baseSelectClass}
            >
                {allowEmpty && <option value="">Min</option>}
                {!allowEmpty && !parts.minute && <option value="">Min</option>}
                {MINUTES.map((minute) => <option key={minute} value={minute}>{minute}</option>)}
            </select>
            <select
                aria-label={`${ariaLabel} AM or PM`}
                value={parts.period}
                onChange={changePeriod}
                disabled={disabled || (!parts.hour && allowEmpty)}
                className={baseSelectClass}
            >
                <option value="AM">AM</option>
                <option value="PM">PM</option>
            </select>
        </div>
    );
}
