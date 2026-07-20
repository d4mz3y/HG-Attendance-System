import React, { useEffect, useState } from 'react';
import api from '../api';

export default function Settings() {
    const [form, setForm] = useState({
        shift_start: '08:00',
        shift_end: '17:00',
        scan_debounce_seconds: 120,
        branch_label: 'Headquarters',
        grace_period_minutes: 0,
        enable_alerts: true,
        enable_scheduled_reports: false,
        report_email: '',
        report_frequency: 'daily',
        scan_allowed_ips: '',
        kiosk_lockdown: false,
    });
    const [saved, setSaved] = useState(false);
    const [role, setRole] = useState('admin');

    useEffect(() => {
        api.get('/settings').then((r) => {
            setForm((f) => ({
                ...f,
                shift_start: r.data.shift_start,
                shift_end: r.data.shift_end,
                scan_debounce_seconds: r.data.scan_debounce_seconds,
                branch_label: r.data.branch_label,
                grace_period_minutes: r.data.grace_period_minutes ?? 0,
                enable_alerts: r.data.enable_alerts ?? true,
                enable_scheduled_reports: r.data.enable_scheduled_reports ?? false,
                report_email: r.data.report_email ?? '',
                report_frequency: r.data.report_frequency ?? 'daily',
                scan_allowed_ips: r.data.scan_allowed_ips ?? '',
                kiosk_lockdown: r.data.kiosk_lockdown ?? false,
            }));
        });
        api.get('/user').then((r) => setRole(r.data.user?.role ?? 'admin'));
    }, []);

    const change = (e) => {
        const { name, value, type, checked } = e.target;
        setForm((f) => ({ ...f, [name]: type === 'checkbox' ? checked : value }));
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaved(false);
        await api.put('/settings', form);
        setSaved(true);
        window.setTimeout(() => setSaved(false), 2500);
    };

    const isSuperAdmin = role === 'super_admin';

    return (
        <div className="mx-auto max-w-xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Settings</h1>
                <p className="text-sm text-slate-500">Shift windows, kiosk behaviour, and reports.</p>
            </div>

            <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 className="text-sm font-semibold uppercase text-slate-500">Shift</h3>
                <label className="block text-sm font-medium text-slate-700">
                    Standard shift start
                    <input id="shift_start" type="time" name="shift_start" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.shift_start} onChange={change} required />
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Standard shift end
                    <input id="shift_end" type="time" name="shift_end" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.shift_end} onChange={change} required />
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Grace period (minutes)
                    <input id="grace_period_minutes" type="number" name="grace_period_minutes" min={0} max={120} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.grace_period_minutes} onChange={change} />
                    <span className="mt-1 block text-xs text-slate-500">Minutes after shift start before a clock-in counts as late. 0 = no grace.</span>
                </label>

                <h3 className="text-sm font-semibold uppercase text-slate-500 pt-2">Kiosk</h3>
                <label className="block text-sm font-medium text-slate-700">
                    Scan debounce (seconds)
                    <input id="scan_debounce_seconds" type="number" name="scan_debounce_seconds" min={0} max={600} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.scan_debounce_seconds} onChange={change} required />
                    <span className="mt-1 block text-xs text-slate-500">Ignores repeat scans for the same employee within this window.</span>
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Branch label
                    <input id="branch_label" name="branch_label" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.branch_label} onChange={change} required />
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Allowed scan IPs (comma-separated)
                    <input id="scan_allowed_ips" name="scan_allowed_ips" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.scan_allowed_ips} onChange={change} placeholder="e.g. 192.168.1.0/24,10.0.0.1" />
                    <span className="mt-1 block text-xs text-slate-500">Leave blank to allow all IPs.</span>
                </label>
                {isSuperAdmin && (
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input id="kiosk_lockdown" type="checkbox" name="kiosk_lockdown" checked={form.kiosk_lockdown} onChange={change} />
                        Kiosk lockdown mode (disable navigation)
                    </label>
                )}

                <h3 className="text-sm font-semibold uppercase text-slate-500 pt-2">Alerts</h3>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input id="enable_alerts" type="checkbox" name="enable_alerts" checked={form.enable_alerts} onChange={change} />
                    Enable missed punch and absence alerts
                </label>

                <h3 className="text-sm font-semibold uppercase text-slate-500 pt-2">Scheduled reports</h3>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input id="enable_scheduled_reports" type="checkbox" name="enable_scheduled_reports" checked={form.enable_scheduled_reports} onChange={change} />
                    Enable scheduled email reports
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Report email
                    <input id="report_email" type="email" name="report_email" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.report_email} onChange={change} />
                </label>
                <label className="block text-sm font-medium text-slate-700">
                    Frequency
                    <select id="report_frequency" name="report_frequency" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.report_frequency} onChange={change}>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </label>

                {saved && <div className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Saved.</div>}

                <button type="submit" className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Save settings
                </button>
            </form>
        </div>
    );
}
