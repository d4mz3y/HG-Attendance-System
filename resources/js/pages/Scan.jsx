import React, { useEffect, useRef, useState, useCallback } from 'react';
import { useToast } from '../components/Toast';
import api from '../api';

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
    const { addToast } = useToast();
    const submitTimerRef = useRef(null);
    const scanLockRef = useRef(false);

    const focusInput = useCallback(() => {
        requestAnimationFrame(() => {
            inputRef.current?.focus();
            inputRef.current?.scrollIntoView({ behavior: 'instant', block: 'center' });
        });
    }, []);

    useEffect(() => {
        focusInput();
        const id = window.setInterval(focusInput, 2000);
        return () => window.clearInterval(id);
    }, [focusInput]);

    useEffect(() => {
        if (!modal) {
            return undefined;
        }
        const t = window.setTimeout(() => setModal(null), 5000);
        return () => window.clearTimeout(t);
    }, [modal]);

    const dismissModal = useCallback(() => {
        setModal(null);
        focusInput();
    }, [focusInput]);

    useEffect(() => {
        if (modal) {
            const id = window.setTimeout(dismissModal, 5000);
            return () => window.clearTimeout(id);
        }
    }, [modal, dismissModal]);

    const submit = useCallback(async (code) => {
        const trimmed = code.trim();
        if (!trimmed || scanLockRef.current) {
            return;
        }

        scanLockRef.current = true;
        setBuffer('');
        setQueued(false);

        if (submitTimerRef.current) {
            clearTimeout(submitTimerRef.current);
            submitTimerRef.current = null;
        }

        try {
            const res = await api.post('/scan', { code: trimmed });

            const payload = res.data;
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
            const payload = err.response?.data;
            if (err.response?.status === 422 && payload?.queued) {
                setQueued(true);
                setModal({ variant: 'warn', title: 'Offline mode', message: 'Scan queued for sync when back online.' });
            } else if (payload) {
                handleError(payload);
            } else {
                addToast('Unable to reach server. Please try again.', 'error');
                setModal({ variant: 'error', title: 'Network error', message: 'Unable to reach server. Please try again.' });
            }
        }

        scanLockRef.current = false;
        focusInput();
    }, [addToast, focusInput]);

    const handleError = useCallback((payload) => {
        if (payload.error === 'not_found') {
            addToast('This barcode is not registered.', 'error');
            setModal({ variant: 'error', title: 'Staff not found', message: 'This barcode is not registered.' });
        } else if (payload.error === 'inactive') {
            addToast('This employee is inactive.', 'error');
            setModal({ variant: 'error', title: 'Access denied', message: 'This employee is inactive.' });
        } else if (payload.error === 'on_leave') {
            addToast(payload.message, 'warning');
            setModal({ variant: 'warn', title: 'On leave', message: payload.message });
        } else if (payload.error === 'debounce') {
            addToast(payload.message, 'warning');
            setModal({ variant: 'warn', title: 'Please wait', message: payload.message });
        } else {
            addToast(payload.message ?? 'Unable to process scan.', 'error');
            setModal({ variant: 'error', title: 'Scan error', message: payload.message ?? 'Unable to process scan.' });
        }
    }, [addToast]);

    const onKeyDown = useCallback((e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            void submit(buffer);
        }
    }, [buffer, submit]);

    const onInputChange = useCallback((e) => {
        const val = e.target.value;
        setBuffer(val);

        if (submitTimerRef.current) {
            clearTimeout(submitTimerRef.current);
        }

        submitTimerRef.current = window.setTimeout(() => {
            if (val.trim()) {
                void submit(val);
            }
        }, 80);
    }, [submit]);

    useEffect(() => {
        return () => {
            if (submitTimerRef.current) {
                clearTimeout(submitTimerRef.current);
            }
        };
    }, []);

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center bg-slate-950 px-4 text-white">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.15),_transparent_55%)]" />

            <div className="relative z-10 max-w-3xl text-center">
                <div className="mx-auto mb-4 h-24 w-24 overflow-hidden rounded-xl">
                    <img src="/logo.png" alt="Hogan Guards" className="h-full w-full object-contain" />
                </div>
                <p className="text-sm font-semibold uppercase tracking-[0.35em] text-hg-gold">Hogan Guards HQ</p>
                <h1 className="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Staff attendance</h1>
                <p className="mt-4 text-lg text-slate-300">Scan your ID barcode to clock in or out.</p>
            </div>

            <input
                ref={inputRef}
                value={buffer}
                onChange={onInputChange}
                onKeyDown={onKeyDown}
                onBlur={focusInput}
                className="absolute left-0 top-0 h-px w-px opacity-0"
                autoComplete="off"
                autoFocus
            />

            <div className="relative z-10 mt-10 rounded-2xl border border-white/10 bg-white/5 px-6 py-4 text-sm text-slate-200 backdrop-blur">
                Scanner ready — input is captured automatically.
                {queued && <span className="ml-2 text-amber-300">(Offline queue active)</span>}
            </div>

            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={dismissModal}>
                    <div
                        className={`w-full max-w-lg scale-100 transform rounded-3xl border p-8 shadow-2xl transition-all duration-300 ${
                            modal.variant === 'success'
                                ? 'border-emerald-400/40 bg-slate-900'
                                : modal.variant === 'warn'
                                  ? 'border-amber-400/40 bg-slate-900'
                                  : 'border-red-400/40 bg-slate-900'
                        }`}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <button
                            type="button"
                            onClick={dismissModal}
                            className="absolute top-4 right-4 text-slate-400 hover:text-white text-2xl leading-none"
                            aria-label="Close"
                        >
                            ×
                        </button>
                        {modal.variant === 'success' && modal.staff && (
                            <div className="flex flex-col items-center text-center">
                                <div className="h-36 w-36 overflow-hidden rounded-3xl border-2 border-white/20 bg-slate-800 shadow-lg">
                                    {modal.staff.photo_url ? (
                                        <img src={modal.staff.photo_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full items-center justify-center text-4xl font-bold text-slate-500">
                                            {modal.staff.full_name?.slice(0, 1)}
                                        </div>
                                    )}
                                </div>
                                <h2 className="mt-5 text-3xl font-bold">{modal.staff.full_name}</h2>
                                <p className="mt-2 text-lg text-slate-300">
                                    {modal.staff.job_title}
                                    {modal.staff.job_title && modal.staff.department ? ' · ' : ''}
                                    {modal.staff.department}
                                </p>
                                <div className="mt-6 flex items-center gap-3">
                                    <div className={`h-16 w-16 rounded-full ${modal.action === 'in' ? 'bg-emerald-500/20' : 'bg-sky-500/20'} flex items-center justify-center text-3xl`}>
                                        {modal.action === 'in' ? '✅' : '🚪'}
                                    </div>
                                    <div className="text-left">
                                        <p className={`text-2xl font-black ${modal.action === 'in' ? 'text-emerald-400' : 'text-sky-400'}`}>
                                            {modal.action === 'in' ? 'Clocked IN' : 'Clocked OUT'}
                                        </p>
                                        <p className="text-sm text-slate-400">{modal.timestamp}</p>
                                    </div>
                                </div>
                                {modal.staff.department && (
                                    <div className="mt-4 text-sm text-slate-500">
                                        Department: {modal.staff.department}
                                    </div>
                                )}
                            </div>
                        )}
                        {modal.variant !== 'success' && (
                            <div className="text-center">
                                <div className={`mx-auto mb-4 h-16 w-16 rounded-full flex items-center justify-center text-3xl ${
                                    modal.variant === 'warn' ? 'bg-amber-500/20' : 'bg-red-500/20'
                                }`}>
                                    {modal.variant === 'warn' ? '⚠️' : '❌'}
                                </div>
                                <h2 className="text-2xl font-bold">{modal.title}</h2>
                                <p className="mt-3 text-base text-slate-300">{modal.message}</p>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}