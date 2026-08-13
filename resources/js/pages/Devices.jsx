import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { useToast } from '../components/Toast';
import { formatDateTime } from '../timeFormat';

function apiError(error, fallback) {
    return Object.values(error.response?.data?.errors ?? {}).flat()[0]
        ?? error.response?.data?.message
        ?? fallback;
}

function status(device) {
    if (!device.is_active) return { label: 'Disabled', className: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-200' };
    if (!device.paired) return { label: 'Waiting for browser', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200' };

    return { label: 'Active', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200' };
}

export default function Devices() {
    const { addToast } = useToast();
    const [device, setDevice] = useState(null);
    const [form, setForm] = useState({ name: '', allowed_ips: '' });
    const [saving, setSaving] = useState(false);
    const [eventHistory, setEventHistory] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        setLoadError('');
        try {
            const { data } = await api.get('/devices');
            const reception = data.data?.[0] ?? null;
            if (!reception) throw new Error('The reception scanner could not be prepared. Please try again.');

            setDevice(reception);
            setForm({ name: reception.name, allowed_ips: reception.allowed_ips ?? '' });

            return reception;
        } catch (error) {
            setDevice(null);
            setLoadError(apiError(error, 'Unable to load the reception scanner.'));
            throw error;
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load().catch((error) => addToast(apiError(error, 'Unable to load the reception scanner.'), 'error'));
    }, [addToast, load]);

    const save = async (event) => {
        event.preventDefault();
        if (!device) return;
        setSaving(true);
        try {
            const { data } = await api.put(`/devices/${device.id}`, form);
            setDevice(data.device);
            setForm({ name: data.device.name, allowed_ips: data.device.allowed_ips ?? '' });
            addToast('Reception scanner settings saved.', 'success');
        } catch (error) {
            addToast(apiError(error, 'Unable to save reception scanner settings.'), 'error');
        } finally {
            setSaving(false);
        }
    };

    const setEnabled = async (enable) => {
        if (!device) return;
        const action = enable ? 'enable' : 'disable';
        if (!window.confirm(`${enable ? 'Enable' : 'Disable'} reception scanning${enable ? '' : ' immediately'}?`)) return;
        try {
            const { data } = await api.post(`/devices/${device.id}/${action}`);
            setDevice(data.device);
            addToast(`Reception scanner ${enable ? 'enabled' : 'disabled'}.`, 'success');
        } catch (error) {
            addToast(apiError(error, `Unable to ${action} the reception scanner.`), 'error');
        }
    };

    const repair = async () => {
        if (!device) return;
        if (!window.confirm('Re-pair the reception browser? Only do this after confirming there are no unsynced attendance events in its Device queue. The next approved browser visit to /scan will pair automatically.')) return;
        try {
            const { data } = await api.post(`/devices/${device.id}/re-pair`, { confirm_queue_resolved: true });
            setDevice(data.device);
            addToast(data.message, 'success');
        } catch (error) {
            addToast(apiError(error, 'Unable to prepare the reception browser for re-pairing.'), 'error');
        }
    };

    const showHistory = async () => {
        if (!device) return;
        try {
            const [eventsResponse, recoveriesResponse] = await Promise.all([
                api.get(`/devices/${device.id}/events`, { params: { per_page: 50 } }),
                api.get(`/devices/${device.id}/recoveries`, { params: { per_page: 50 } }),
            ]);
            setEventHistory({
                device,
                events: eventsResponse.data.data ?? [],
                recoveries: recoveriesResponse.data.data ?? [],
            });
        } catch (error) {
            addToast(apiError(error, 'Unable to load reception scanner history.'), 'error');
        }
    };

    const approveRecovery = async (recovery) => {
        const reason = window.prompt('Describe the manual attendance check or correction completed for these blocked events (at least 10 characters).');
        if (!reason) return;
        try {
            await api.post(`/devices/${eventHistory.device.id}/recoveries/${recovery.id}/approve`, { reason });
            await showHistory();
            addToast('Recovery approved for this exact event set. The reception scanner can now check the request and continue.', 'success');
        } catch (error) {
            addToast(apiError(error, 'Unable to approve this recovery request.'), 'error');
        }
    };

    if (loading && !device) {
        return <div className="text-sm text-slate-500 dark:text-slate-400" role="status">Loading reception scanner…</div>;
    }

    if (!device) {
        return (
            <section className="max-w-xl rounded-2xl border border-red-200 bg-red-50 p-6 text-slate-900 shadow-sm dark:border-red-900/70 dark:bg-red-950/30 dark:text-slate-100" role="alert">
                <h1 className="text-xl font-bold">Reception scanner is unavailable</h1>
                <p className="mt-2 text-sm leading-6 text-red-800 dark:text-red-200">{loadError || 'The reception scanner could not be loaded.'}</p>
                <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">After IT finishes the server update or resolves the connection problem, try again. Staff never need a token or passcode.</p>
                <button type="button" onClick={() => void load().catch((error) => addToast(apiError(error, 'Unable to load the reception scanner.'), 'error'))} className="mt-5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 dark:bg-sky-600 dark:hover:bg-sky-500">Try again</button>
            </section>
        );
    }

    const deviceStatus = status(device);

    return (
        <div className="space-y-6 text-slate-900 dark:text-slate-100">
            <div>
                <h1 className="text-2xl font-bold">Reception scanner</h1>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">One fixed browser at reception handles staff clock-ins and clock-outs. It starts itself; staff never enter a device token or passcode.</p>
            </div>

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2"><h2 className="text-lg font-semibold">{device.name}</h2><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${deviceStatus.className}`}>{deviceStatus.label}</span></div>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">{device.paired ? `Paired ${device.paired_at ? formatDateTime(device.paired_at) : ''}` : 'Set the exact reception computer IP below, then open /scan on that computer.'}</p>
                    </div>
                    <button type="button" onClick={showHistory} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">History & recovery</button>
                </div>
                <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Approved address</dt><dd className="mt-1 break-all font-mono text-slate-800 dark:text-slate-100">{device.allowed_ips || 'Not configured'}</dd></div>
                    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Last seen</dt><dd className="mt-1 text-slate-800 dark:text-slate-100">{device.last_seen_at ? formatDateTime(device.last_seen_at) : 'Never'}</dd></div>
                    <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/70"><dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Last network IP</dt><dd className="mt-1 font-mono text-slate-800 dark:text-slate-100">{device.last_ip || '—'}</dd></div>
                </dl>
            </section>

            <form onSubmit={save} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 className="text-lg font-semibold">Reception configuration</h2>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">Pin the scanner to the reception computer&apos;s one static IP address. A range is deliberately not accepted, so another computer cannot claim the scanner.</p>
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">Display name<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" /></label>
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">Reception computer IP address<input required value={form.allowed_ips} onChange={(event) => setForm({ ...form, allowed_ips: event.target.value })} placeholder="192.168.1.25" className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-slate-900 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" /></label>
                </div>
                <div className="mt-5 flex flex-wrap gap-3"><button disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500">{saving ? 'Saving…' : 'Save configuration'}</button>{device.is_active ? <button type="button" onClick={() => setEnabled(false)} className="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/50">Disable scanning</button> : <button type="button" onClick={() => setEnabled(true)} className="rounded-lg border border-emerald-300 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-300 dark:hover:bg-emerald-950/50">Enable scanning</button>}{device.paired && <button type="button" onClick={repair} className="rounded-lg border border-amber-300 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50 dark:border-amber-900 dark:text-amber-200 dark:hover:bg-amber-950/50">Re-pair replacement browser</button>}</div>
            </form>

            {eventHistory && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-black/70 p-4">
                    <div className="mx-auto my-10 max-w-5xl rounded-2xl bg-white p-6 text-slate-900 shadow-2xl dark:bg-slate-900 dark:text-slate-100">
                        <div className="flex justify-between gap-4"><div><h2 className="text-xl font-bold">Reception scanner history</h2><p className="text-sm text-slate-600 dark:text-slate-400">Review server-received events and exact blocked-queue recovery requests.</p></div><button type="button" onClick={() => setEventHistory(null)} className="text-2xl text-slate-500 hover:text-slate-900 dark:hover:text-white">×</button></div>
                        <h3 className="mt-6 font-semibold">Blocked-queue recovery</h3>
                        <div className="mt-2 space-y-3">{eventHistory.recoveries.map((recovery) => <div key={recovery.request_uuid} className="rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700"><div className="flex flex-wrap items-center justify-between gap-2"><span className="font-mono text-xs">{recovery.request_uuid}</span><span className="font-semibold uppercase">{recovery.status}</span></div><div className="mt-3 space-y-2">{(recovery.requested_events ?? []).map((event) => <div key={event.event_id} className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800"><span className="font-mono">#{event.sequence} · {event.code}</span><span className="ml-3 text-slate-500 dark:text-slate-400">{formatDateTime(event.occurred_at)}</span><div className="mt-1 text-red-700 dark:text-red-300">{event.error || 'blocked'}: {event.message || 'No client message provided'}</div></div>)}</div>{recovery.review_reason && <p className="mt-3 text-slate-600 dark:text-slate-400">Reviewed by {recovery.reviewer?.username ?? 'deleted user'}: {recovery.review_reason}</p>}{['pending', 'expired'].includes(recovery.status) && eventHistory.device.is_active && <button type="button" onClick={() => approveRecovery(recovery)} className="mt-3 rounded-lg bg-amber-600 px-3 py-2 font-semibold text-white hover:bg-amber-500">Approve after manual check</button>}</div>)}{eventHistory.recoveries.length === 0 && <p className="rounded-lg bg-slate-50 p-4 text-slate-500 dark:bg-slate-800 dark:text-slate-400">No recovery requests.</p>}</div>
                        <h3 className="mt-8 font-semibold">Latest server-received events</h3>
                        <div className="mt-2 overflow-x-auto"><table className="min-w-full text-sm"><thead className="text-left text-xs uppercase text-slate-500 dark:text-slate-400"><tr><th className="p-2">Sequence</th><th className="p-2">Occurred</th><th className="p-2">Staff code</th><th className="p-2">Status</th><th className="p-2">Reason</th></tr></thead><tbody className="divide-y divide-slate-200 dark:divide-slate-800">{eventHistory.events.map((event) => <tr key={event.event_uuid}><td className="p-2">{event.sequence}</td><td className="p-2">{formatDateTime(event.occurred_at)}</td><td className="p-2 font-mono text-xs">{event.staff_id_code}</td><td className="p-2">{event.status}</td><td className="p-2 text-red-600 dark:text-red-300">{event.error_message || '—'}</td></tr>)}</tbody></table></div>
                    </div>
                </div>
            )}
        </div>
    );
}
