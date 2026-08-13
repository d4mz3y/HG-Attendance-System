import React from 'react';

/**
 * A familiar visual control for password fields. The label remains available
 * to screen readers and on hover, while the eye/eye-off state is instantly
 * recognizable without adding text inside a narrow input.
 */
export default function PasswordVisibilityButton({ visible, onClick, label = 'password' }) {
    const action = visible ? 'Hide' : 'Show';

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={`${action} ${label}`}
            aria-pressed={visible}
            title={`${action} ${label}`}
            className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-hg-blue dark:text-slate-300 dark:hover:text-white"
        >
            {visible ? (
                <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="m3 3 18 18" />
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                    <path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c5.4 0 9.3 4.6 10 8-.3 1.5-1.3 3.4-3 4.8" />
                    <path d="M6.6 6.6C4.5 8 2.7 10.2 2 12c.7 3.4 4.6 8 10 8 1.7 0 3.2-.4 4.5-1.1" />
                </svg>
            ) : (
                <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
            )}
        </button>
    );
}
