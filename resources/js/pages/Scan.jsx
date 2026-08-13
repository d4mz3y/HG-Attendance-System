import React, { useCallback, useEffect, useRef, useState } from 'react';
import api from '../api';
import { useToast } from '../components/Toast';
import {
    cachedKioskConfig,
    captureScan,
    loadDeviceToken,
    pairReceptionScanner,
    queueSummary,
    recentQueueEvents,
    recoverBlockedEvents,
    syncQueuedScans,
    updateKioskConfig,
} from '../kioskOffline';
import { supportsServiceWorkerRegistration } from '../pwaSupport';
import { formatDateTime } from '../timeFormat';

function formatScanTime(iso) {
    return new Date(iso).toLocaleString(undefined, {
        hour: 'numeric', minute: '2-digit', hour12: true, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    });
}

function errorMessage(error, fallback) {
    return error.response?.data?.message ?? error.message ?? fallback;
}

export default function Scan() {
    const inputRef = useRef(null);
    const submitTimerRef = useRef(null);
    const scanLockRef = useRef(false);
    const syncLockRef = useRef(false);
    const [buffer, setBuffer] = useState('');
    const [modal, setModal] = useState(null);
    const [token, setToken] = useState(undefined);
    const [config, setConfig] = useState(null);
    const [connected, setConnected] = useState(navigator.onLine);
    const [authorizationError, setAuthorizationError] = useState('');
    const [setupError, setSetupError] = useState('');
    const [summary, setSummary] = useState({ pending: 0, blocked: 0, rejected: 0 });
    const [queueEvents, setQueueEvents] = useState([]);
    const [showQueue, setShowQueue] = useState(false);
    const [bootAttempt, setBootAttempt] = useState(0);
    const secureContext = Boolean(window.isSecureContext);
    const serviceWorkerSupported = supportsServiceWorkerRegistration(window);
    const { addToast } = useToast();

    const refreshQueue = useCallback(async () => {
        setSummary(await queueSummary());
        setQueueEvents(await recentQueueEvents());
    }, []);

    const applyConfig = useCallback((nextConfig) => {
        setConfig(nextConfig);
        const override = localStorage.getItem('hg_theme_override');
        const dark = override === 'dark' || (override !== 'light' && nextConfig?.dark_mode_default !== false);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    }, []);

    const focusInput = useCallback(() => {
        if (!token || authorizationError || setupError || showQueue || modal) return;
        requestAnimationFrame(() => inputRef.current?.focus());
    }, [authorizationError, modal, setupError, showQueue, token]);

    const showScanResult = useCallback((result) => {
        if (result?.ok) {
            setModal({ variant: 'success', action: result.action, staff: result.staff, timestamp: formatScanTime(result.timestamp) });
            return;
        }

        const error = result?.error;
        const title = error === 'not_found' ? 'Staff not found'
            : error === 'inactive' ? 'Access denied'
                : error === 'on_leave' ? 'On leave'
                    : error === 'already_signed_out' ? 'Signed out'
                        : error === 'cooldown' || error === 'debounce' ? 'Please wait'
                            : error === 'out_of_order' ? 'Manual review required'
                                : 'Scan rejected';
        const variant = ['on_leave', 'already_signed_out', 'cooldown', 'debounce'].includes(error) ? 'warn' : 'error';
        setModal({ variant, title, message: result?.message ?? 'Unable to process scan.' });
        addToast(result?.message ?? 'Unable to process scan.', variant === 'warn' ? 'warning' : 'error');
    }, [addToast]);

    const syncNow = useCallback(async (targetEventId = null) => {
        if (!token || !secureContext || syncLockRef.current || !navigator.onLine) return null;
        syncLockRef.current = true;
        try {
            const results = await syncQueuedScans();
            setConnected(true);
            setAuthorizationError('');
            await refreshQueue();

            return targetEventId ? results.find((result) => result.event_id === targetEventId) ?? null : null;
        } catch (error) {
            setConnected(false);
            if (error.authorization) setAuthorizationError(error.message);
            await refreshQueue();

            return null;
        } finally {
            syncLockRef.current = false;
        }
    }, [refreshQueue, secureContext, token]);

    useEffect(() => {
        if (import.meta.env.PROD && serviceWorkerSupported) {
            void window.navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        let active = true;
        const setIfActive = (callback) => { if (active) callback(); };

        const boot = async () => {
            setIfActive(() => {
                setSetupError('');
                setAuthorizationError('');
                setToken(undefined);
            });

            try {
                const [storedToken, cached] = await Promise.all([loadDeviceToken(), cachedKioskConfig()]);
                if (cached) setIfActive(() => applyConfig(cached));
                await refreshQueue();

                if (!secureContext) {
                    setIfActive(() => {
                        setConnected(false);
                        setToken(null);
                        setSetupError('This reception scanner needs the trusted HTTPS address before it can securely start.');
                    });

                    return;
                }

                let activeToken = storedToken;
                let nextConfig;
                if (activeToken) {
                    try {
                        const { data } = await api.get('/scan/config', { headers: { 'X-Device-Token': activeToken } });
                        nextConfig = data;
                    } catch (error) {
                        const unresolved = await queueSummary();
                        if (unresolved.pending > 0 || unresolved.blocked > 0) {
                            setIfActive(() => {
                                setToken(activeToken);
                                setConnected(false);
                                setAuthorizationError('The saved reception credential changed while this browser still has queued attendance events. Do not clear this browser; ask IT to review the queue.');
                            });

                            return;
                        }

                        // IT can explicitly re-pair a replacement browser in
                        // Scan devices. If that has already happened, this
                        // retry silently starts the approved reception device.
                        const paired = await pairReceptionScanner({ replaceExisting: true });
                        activeToken = paired.token;
                        nextConfig = paired.config;
                    }
                } else {
                    const paired = await pairReceptionScanner();
                    activeToken = paired.token;
                    nextConfig = paired.config;
                }

                await updateKioskConfig(nextConfig);
                setIfActive(() => {
                    setToken(activeToken);
                    applyConfig(nextConfig);
                    setConnected(true);
                });
            } catch (error) {
                setIfActive(() => {
                    setToken(null);
                    setConnected(false);
                    setSetupError(errorMessage(error, 'The reception scanner could not start.'));
                });
            }
        };

        void boot();

        return () => { active = false; };
    }, [applyConfig, bootAttempt, refreshQueue, secureContext, serviceWorkerSupported]);

    useEffect(() => {
        const online = () => {
            setConnected(true);
            if (token) void syncNow();
            else setBootAttempt((attempt) => attempt + 1);
        };
        const offline = () => setConnected(false);
        window.addEventListener('online', online);
        window.addEventListener('offline', offline);
        const interval = window.setInterval(() => void syncNow(), 30000);

        return () => {
            window.removeEventListener('online', online);
            window.removeEventListener('offline', offline);
            window.clearInterval(interval);
        };
    }, [syncNow, token]);

    useEffect(() => {
        focusInput();
        const interval = window.setInterval(focusInput, 2000);

        return () => window.clearInterval(interval);
    }, [focusInput]);

    useEffect(() => {
        if (!modal) return undefined;
        const timeout = window.setTimeout(() => setModal(null), 5000);

        return () => window.clearTimeout(timeout);
    }, [modal]);

    const submit = useCallback(async (rawCode) => {
        const code = rawCode.trim();
        if (!code || scanLockRef.current || !token || authorizationError || summary.blocked > 0) return;
        scanLockRef.current = true;
        setBuffer('');
        if (submitTimerRef.current) {
            window.clearTimeout(submitTimerRef.current);
            submitTimerRef.current = null;
        }

        try {
            const captured = await captureScan(code);
            await refreshQueue();
            setModal({ variant: 'warn', title: 'Scan captured', message: 'The signed scan is saved on this reception device and is being synchronized.' });
            scanLockRef.current = false;
            const synced = await syncNow(captured.event_id);
            if (synced?.accepted) {
                showScanResult(synced.result);
            } else if (synced && !synced.retryable) {
                setModal({ variant: 'error', title: 'Sync blocked', message: synced.message });
            } else {
                setModal({ variant: 'warn', title: 'Saved offline', message: 'The signed scan is safely queued and will replay in order when the server is reachable.' });
            }
        } catch (error) {
            setModal({ variant: 'error', title: 'Capture failed', message: error.message ?? 'The scan could not be saved.' });
        } finally {
            scanLockRef.current = false;
            focusInput();
        }
    }, [authorizationError, focusInput, refreshQueue, showScanResult, summary.blocked, syncNow, token]);

    const recoverBlockedQueue = async () => {
        if (!token || !connected || !secureContext || summary.blocked === 0) return;
        try {
            const outcome = await recoverBlockedEvents();
            await refreshQueue();
            setAuthorizationError('');
            if (outcome.status === 'recovered') {
                addToast(`${outcome.count} IT-reviewed event(s) removed. Pending events were safely re-signed and resequenced.`, 'success');
                void syncNow();
            } else {
                addToast(outcome.message ?? `Recovery request ${outcome.requestId ?? ''} is waiting for IT review.`, 'warning');
            }
        } catch (error) {
            if (error.authorization) setAuthorizationError(error.message);
            addToast(error.response?.data?.message ?? error.message ?? 'The blocked queue could not be submitted for recovery.', 'error');
        }
    };

    const onInputChange = (event) => {
        const value = event.target.value;
        setBuffer(value);
        if (submitTimerRef.current) window.clearTimeout(submitTimerRef.current);
        submitTimerRef.current = window.setTimeout(() => { if (value.trim()) void submit(value); }, 150);
    };

    if (token === undefined) {
        return <div className="flex min-h-screen items-center justify-center bg-slate-950 text-slate-300">Starting reception scanner…</div>;
    }

    if (!token) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4 text-white">
                <div className="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-8 text-center">
                    <img src="/logo.png" alt="Hogan Guards" className="mx-auto h-20 w-20 object-contain" />
                    <h1 className="mt-4 text-2xl font-bold">Reception scanner needs IT</h1>
                    <p className="mt-3 text-sm leading-6 text-slate-300">{setupError || 'The reception scanner is not ready yet.'}</p>
                    <p className="mt-4 text-xs leading-5 text-slate-500">Staff never need a passcode here. IT only needs to configure this reception computer&apos;s IP address in Scan devices, or deliberately re-pair a replacement browser.</p>
                    <button type="button" onClick={() => setBootAttempt((attempt) => attempt + 1)} className="mt-6 rounded-lg bg-hg-blue px-4 py-2.5 text-sm font-semibold text-white">Try again</button>
                </div>
            </div>
        );
    }

    const scannerDisabled = Boolean(!secureContext || authorizationError || summary.blocked > 0);

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center bg-slate-950 px-4 text-white">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(56,189,248,0.15),_transparent_55%)]" />
            <div className="absolute right-4 top-4 z-20 flex items-center gap-2 text-xs">
                <span className={`h-2 w-2 rounded-full ${connected ? 'bg-emerald-400' : 'bg-amber-400'}`} />
                {connected ? 'Server connected' : 'Offline'}
                {summary.pending > 0 && <button type="button" onClick={() => setShowQueue(true)} className="rounded-full bg-amber-500/20 px-3 py-1 text-amber-300">{summary.pending} queued</button>}
                {(summary.blocked > 0 || summary.rejected > 0) && <button type="button" onClick={() => setShowQueue(true)} className="rounded-full bg-red-500/20 px-3 py-1 text-red-300">Review queue</button>}
            </div>
            <div className="relative z-10 max-w-3xl text-center">
                <img src="/logo.png" alt="Hogan Guards" className="mx-auto mb-4 h-24 w-24 object-contain" />
                <p className="text-sm font-semibold uppercase tracking-[0.35em] text-hg-gold">{config?.branch_label ?? 'Hogan Guards'}</p>
                <h1 className="mt-3 text-4xl font-black sm:text-5xl">Staff attendance</h1>
                <p className="mt-4 text-lg text-slate-300">{scannerDisabled ? 'Scanning is paused. IT action is required.' : 'Scan your ID barcode to clock in or out.'}</p>
                {config?.device && <p className="mt-2 text-xs text-slate-500">{config.device.name}</p>}
            </div>
            <input ref={inputRef} value={buffer} onChange={onInputChange} onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (submitTimerRef.current) {
                        window.clearTimeout(submitTimerRef.current);
                        submitTimerRef.current = null;
                    }
                    void submit(buffer);
                }
            }} onBlur={focusInput} disabled={scannerDisabled} className="absolute h-px w-px opacity-0" autoComplete="off" autoFocus />
            <div className="relative z-10 mt-10 rounded-2xl border border-white/10 bg-white/5 px-6 py-4 text-sm text-slate-200">
                {authorizationError || (summary.blocked ? 'A queued event needs IT review; open the device queue for the reason.' : connected ? 'Scanner ready — signed capture and immediate sync active.' : 'Scanner ready — signed offline capture active.')}
            </div>
            {!serviceWorkerSupported && <div className="relative z-20 mt-3 max-w-xl rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-center text-xs text-amber-200">This browser can scan while this page stays open, but it cannot reliably reopen the offline queue. IT should use a current browser on the trusted HTTPS address.</div>}
            {authorizationError && summary.blocked > 0 && <button type="button" onClick={() => setShowQueue(true)} className="relative z-20 mt-4 rounded-lg border border-red-500/50 px-4 py-2 text-sm text-red-300">Open device queue</button>}
            <div className="absolute bottom-4 z-20 text-xs text-slate-600"><button type="button" onClick={() => setShowQueue(true)} className="underline">Device queue</button></div>

            {modal && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={() => setModal(null)}><div className={`relative w-full max-w-lg rounded-3xl border bg-slate-900 p-8 shadow-2xl ${modal.variant === 'success' ? 'border-emerald-400/40' : modal.variant === 'warn' ? 'border-amber-400/40' : 'border-red-400/40'}`} onClick={(event) => event.stopPropagation()}><button type="button" onClick={() => setModal(null)} className="absolute right-4 top-4 text-2xl text-slate-400">×</button>{modal.variant === 'success' && modal.staff ? <div className="flex flex-col items-center text-center"><div className="h-36 w-36 overflow-hidden rounded-3xl border-2 border-white/20 bg-slate-800">{modal.staff.photo_url ? <img src={modal.staff.photo_url} alt="" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-4xl font-bold text-slate-500">{modal.staff.full_name?.slice(0, 1)}</div>}</div><h2 className="mt-5 text-3xl font-bold">{modal.staff.full_name}</h2><p className="mt-2 text-slate-300">{[modal.staff.job_title, modal.staff.department].filter(Boolean).join(' · ')}</p><p className={`mt-6 text-2xl font-black ${modal.action === 'in' ? 'text-emerald-400' : 'text-sky-400'}`}>{modal.action === 'in' ? 'Clocked IN' : 'Clocked OUT'}</p><p className="text-sm text-slate-400">{modal.timestamp}</p></div> : <div className="text-center"><div className={`mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full text-3xl ${modal.variant === 'warn' ? 'bg-amber-500/20' : 'bg-red-500/20'}`}>{modal.variant === 'warn' ? '⚠️' : '❌'}</div><h2 className="text-2xl font-bold">{modal.title}</h2><p className="mt-3 text-slate-300">{modal.message}</p></div>}</div></div>}

            {showQueue && <div className="fixed inset-0 z-50 overflow-y-auto bg-black/80 p-4"><div className="mx-auto mt-10 max-w-2xl rounded-2xl bg-slate-900 p-6"><div className="flex items-center justify-between"><h2 className="text-xl font-bold">Device queue</h2><button type="button" onClick={() => setShowQueue(false)} className="text-2xl">×</button></div><p className="mt-2 text-sm text-slate-400">Pending events replay strictly in signed sequence. Permanently rejected scans need IT review before this scanner can continue.</p><div className="mt-4 space-y-2">{queueEvents.map((event) => <div key={event.event_id} className="rounded-lg border border-slate-700 p-3 text-sm"><div className="flex justify-between"><span className="font-mono">#{event.sequence} · {event.code}</span><span className={event.status === 'confirmed' ? 'text-emerald-400' : event.status === 'pending' ? 'text-amber-300' : 'text-red-400'}>{event.status}</span></div><div className="mt-1 text-xs text-slate-500">{formatDateTime(event.occurred_at)}</div>{event.last_error && <div className="mt-2 text-sm text-red-300">{event.last_error}</div>}</div>)}{queueEvents.length === 0 && <p className="py-6 text-center text-slate-500">No locally stored events.</p>}</div><div className="mt-5 flex flex-wrap gap-3"><button type="button" onClick={() => void syncNow()} disabled={!connected || summary.pending === 0 || summary.blocked > 0} className="rounded-lg bg-hg-blue px-4 py-2 font-semibold disabled:opacity-40">Sync now</button>{summary.blocked > 0 && <button type="button" onClick={recoverBlockedQueue} disabled={!connected || !secureContext} title={!secureContext ? 'A trusted HTTPS reception address is required before recovery can transmit the device credential.' : ''} className="rounded-lg border border-amber-600 px-4 py-2 text-amber-300 disabled:opacity-40">Request / check IT review</button>}</div></div></div>}
        </div>
    );
}
