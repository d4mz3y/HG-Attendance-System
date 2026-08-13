import React, { useEffect, useId, useMemo, useRef, useState } from 'react';

function staffLabel(staff) {
    return `${staff.full_name} (${staff.staff_id})`;
}

/**
 * A small, dependency-free combobox for choosing a staff member.
 *
 * Native selects become difficult to use once the staff list grows: typing
 * only jumps around the list and does not show matching people. This control
 * filters names, staff IDs, and departments as the user types.
 */
export default function StaffPicker({
    options = [],
    value,
    onChange,
    id,
    name,
    placeholder = 'Search staff by name or ID…',
    emptyLabel = 'All staff',
    required = false,
    disabled = false,
    className = '',
}) {
    const generatedId = useId();
    const inputId = id ?? `staff-picker-${generatedId.replace(/:/g, '')}`;
    const listId = `${inputId}-results`;
    const rootRef = useRef(null);
    const inputRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlightedIndex, setHighlightedIndex] = useState(0);
    const selected = useMemo(
        () => options.find((staff) => String(staff.id) === String(value ?? '')),
        [options, value],
    );
    const selectedLabel = selected ? staffLabel(selected) : '';
    const normalizedQuery = query.trim().toLocaleLowerCase();
    const results = useMemo(() => {
        if (!normalizedQuery) {
            return options.slice(0, 8);
        }

        return options
            .filter((staff) => [staff.full_name, staff.staff_id, staff.department]
                .filter(Boolean)
                .some((field) => String(field).toLocaleLowerCase().includes(normalizedQuery)))
            .slice(0, 12);
    }, [normalizedQuery, options]);

    useEffect(() => {
        setQuery(selectedLabel);
    }, [selectedLabel]);

    useEffect(() => {
        setHighlightedIndex(0);
    }, [normalizedQuery, open]);

    useEffect(() => {
        const closeWhenClickedOutside = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
                setQuery(selectedLabel);
            }
        };

        document.addEventListener('pointerdown', closeWhenClickedOutside);

        return () => document.removeEventListener('pointerdown', closeWhenClickedOutside);
    }, [selectedLabel]);

    const choose = (staff) => {
        onChange(String(staff.id));
        setQuery(staffLabel(staff));
        setOpen(false);
        inputRef.current?.focus();
    };

    const clear = () => {
        onChange('');
        setQuery('');
        setOpen(true);
        inputRef.current?.focus();
    };

    const onKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setHighlightedIndex((index) => Math.min(index + 1, Math.max(0, results.length - 1)));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setHighlightedIndex((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter' && open && results[highlightedIndex]) {
            event.preventDefault();
            choose(results[highlightedIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            setQuery(selectedLabel);
        }
    };

    return (
        <div ref={rootRef} className={`relative ${className}`}>
            {name && <input type="hidden" name={name} value={value ?? ''} />}
            <div className="relative">
                <input
                    ref={inputRef}
                    id={inputId}
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls={listId}
                    aria-expanded={open}
                    aria-activedescendant={open && results[highlightedIndex] ? `${listId}-${results[highlightedIndex].id}` : undefined}
                    autoComplete="off"
                    placeholder={placeholder}
                    value={query}
                    disabled={disabled}
                    required={required}
                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 pr-10 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-400"
                    onFocus={(event) => {
                        setOpen(true);
                        // Let a person immediately replace the current choice
                        // by simply typing the first few letters of a name.
                        event.currentTarget.select();
                    }}
                    onBlur={() => {
                        window.setTimeout(() => {
                            if (!rootRef.current?.contains(document.activeElement)) {
                                setOpen(false);
                                setQuery(selectedLabel);
                            }
                        }, 0);
                    }}
                    onChange={(event) => {
                        const nextQuery = event.target.value;
                        setQuery(nextQuery);
                        setOpen(true);
                        if (nextQuery === '') {
                            onChange('');
                        }
                    }}
                    onKeyDown={onKeyDown}
                />
                {(query || value) && !disabled && (
                    <button
                        type="button"
                        aria-label={`Clear ${emptyLabel.toLocaleLowerCase()} selection`}
                        onClick={clear}
                        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-100"
                    >
                        ×
                    </button>
                )}
            </div>

            {open && !disabled && (
                <div
                    id={listId}
                    role="listbox"
                    className="absolute z-40 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                >
                    {!normalizedQuery && (
                        <p className="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                            Type a name, staff ID, or department to narrow the list.
                        </p>
                    )}
                    {results.map((staff, index) => (
                        <button
                            key={staff.id}
                            id={`${listId}-${staff.id}`}
                            type="button"
                            role="option"
                            aria-selected={String(staff.id) === String(value ?? '')}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => choose(staff)}
                            onMouseEnter={() => setHighlightedIndex(index)}
                            className={`flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm transition ${index === highlightedIndex ? 'bg-sky-50 text-sky-950 dark:bg-sky-500/20 dark:text-sky-50' : 'text-slate-800 hover:bg-slate-50 dark:text-slate-100 dark:hover:bg-slate-800'}`}
                        >
                            <span className="min-w-0">
                                <span className="block truncate font-semibold">{staff.full_name}</span>
                                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{staff.staff_id}</span>
                            </span>
                            {staff.department && <span className="shrink-0 text-xs text-slate-500 dark:text-slate-400">{staff.department}</span>}
                        </button>
                    ))}
                    {results.length === 0 && (
                        <p className="px-3 py-4 text-sm text-slate-500 dark:text-slate-400">No matching staff found.</p>
                    )}
                </div>
            )}
        </div>
    );
}
