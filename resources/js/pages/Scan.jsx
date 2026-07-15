import React, { useEffect, useRef, useState } from 'react';

function formatScanTime(iso) {
    const d = new Date(iso);
    return d.toLocaleString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export default function Scan() {
    const inputRef = useRef(null);
    const [buffer, setBuffer] = useState('');
    const [modal, setModal] = useState(null);
    const [queued, setQueued] = useState(false);

    const focusInput = () => {
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    useEffect(() => {
        focusInput();
        const id = window.setInterval(focusInput, 1500);
        return () => window.clearInterval(id);
    }, []);

    useEffect(() => {
        if (!modal) {
            return undefined;
        }
        const t = window.setTimeout(() => setModal(null), 4000);
        return () => window.clearTimeout(t);
    }, [modal]);

    const submit = async (code) => {
        const trimmed = code.trim();
        if (!trimmed) {
            return;
        }
        setBuffer('');
        setQueued(false);

        try {
            const res = await fetch('/api/scan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ code: trimmed }),
            });

            if (!res.ok) {
                const payload = await res.json();
                if (res.status === 422 && payload?.queued) {
                    setQueued(true);
                    setModal({ variant: 'warn', title: 'Offline mode', message: 'Scan queued for sync when back online.' });
                    return;
                }
                handleError(payload);
                return;
            }

            const payload = await res.json();
            if (payload.ok) {
                if (payload.queued) {
                    setQueued(true);
                    setModal({ variant: 'warn', title: 'Offline mode', message: 'Scan queued for sync when back online.' });
                } else {
                    setModal({
                        variant: 'success',
                        action: payload.action,
                        staff: payload.staff,
                        timestamp: formatScanTime(payload.timestamp),
                    });
                }
            } else {
                handleError(payload);
            }
        } catch (err) {
            setModal({ variant: 'error', title: 'Network error', message: 'Unable to reach server. Please try again.' });
        }
        focusInput();
    };

    const handleError = (payload) => {
        if (payload.error === 'not_found') {
            setModal({ variant: 'error', title: 'Staff not found', message: 'This barcode is not registered.' });
        } else if (payload.error === 'inactive') {
            setModal({ variant: 'error', title: 'Access denied', message: 'This employee is inactive.' });
        } else if (payload.error === 'debounce') {
            setModal({ variant: 'warn', title: 'Please wait', message: payload.message });
        } else {
            setModal({ variant: 'error', title: 'Scan error', message: payload.message ?? 'Unable to process scan.' });
        }
    };

    const onKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            void submit(buffer);
        }
    };

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center bg-slate-950 px-4 text-white">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.15),_transparent_55%)]" />

            <div className="relative z-10 max-w-3xl text-center">
                <div className="mx-auto mb-4 h-16 w-16">
                    <img src="/logo.svg" alt="Hogan Guards" className="h-full w-full" />
                </div>
                <p className="text-sm font-semibold uppercase tracking-[0.35em] text-hg-gold">Hogan Guards HQ</p>
                <h1 className="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Staff attendance</h1>
                <p className="mt-4 text-lg text-slate-300">Scan your ID barcode to clock in or out.</p>
            </div>

            <input
                ref={inputRef}
                value={buffer}
                onChange={(e) => setBuffer(e.target.value)}
                onKeyDown={onKeyDown}
                onBlur={focusInput}
                className="absolute left-0 top-0 h-px w-px opacity-0"
                aria-hidden
                autoComplete="off"
            />

            <div className="relative z-10 mt-10 rounded-2xl border border-white/10 bg-white/5 px-6 py-4 text-sm text-slate-200 backdrop-blur">
                Scanner ready — input is captured automatically.
                {queued && <span className="ml-2 text-amber-300">(Offline queue active)</span>}
            </div>

            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                    <div
                        className={`w-full max-w-md scale-100 transform rounded-3xl border p-6 shadow-2xl transition-all duration-300 ${
                            modal.variant === 'success'
                                ? 'border-emerald-400/40 bg-slate-900'
                                : modal.variant === 'warn'
                                  ? 'border-amber-400/40 bg-slate-900'
                                  : 'border-red-400/40 bg-slate-900'
                        }`}
                    >
                        {modal.variant === 'success' && modal.staff && (
                            <div className="flex flex-col items-center text-center">
                                <div className="h-28 w-28 overflow-hidden rounded-2xl border border-white/10 bg-slate-800">
                                    {modal.staff.photo_url ? (
                                        <img src={modal.staff.photo_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full items-center justify-center text-3xl font-bold text-slate-500">
                                            {modal.staff.full_name?.slice(0, 1)}
                                        </div>
                                    )}
                                </div>
                                <h2 className="mt-4 text-2xl font-bold">{modal.staff.full_name}</h2>
                                <p className="text-slate-300">
                                    {modal.staff.job_title}
                                    {modal.staff.job_title && modal.staff.department ? ' · ' : ''}
                                    {modal.staff.department}
                                </p>
                                <p className="mt-4 text-3xl font-black text-emerald-400">
                                    {modal.action === 'in' ? 'Clocked IN ✅' : 'Clocked OUT 🚪'}
                                </p>
                                <p className="mt-2 text-sm text-slate-400">{modal.timestamp}</p>
                            </div>
                        )}
                        {modal.variant !== 'success' && (
                            <div className="text-center">
                                <h2 className="text-2xl font-bold">{modal.title}</h2>
                                <p className="mt-3 text-slate-300">{modal.message}</p>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
