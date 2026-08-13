import React, { useEffect, useState } from 'react';
import api from '../api';
import { useToast } from '../components/Toast';
import { useAuth } from '../AuthContext';
import TimePicker from '../components/TimePicker';

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const INITIAL = {
    shift_start: '08:00', shift_end: '17:00', default_work_days: [1, 2, 3, 4, 5],
    scan_debounce_seconds: 2, scan_cooldown_seconds: 1800, offline_max_age_hours: 72, scan_clock_skew_seconds: 300,
    branch_label: 'Headquarters', grace_period_minutes: 0, default_break_minutes: 60, enable_alerts: true,
    missed_clock_out_alert_minutes: 60, absence_alert_minutes: 60,
    enable_scheduled_reports: false, report_email: '', report_frequency: 'daily',
    scan_allowed_ips: '', dark_mode_default: false,
};

const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 shadow-sm outline-none focus:border-hg-blue focus:ring-2 focus:ring-hg-blue/20 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-400/20';
const fieldLabelClass = 'block text-sm font-medium text-slate-700 dark:text-slate-200';

export default function Settings() {
    const [form, setForm] = useState(INITIAL);
    const [saving, setSaving] = useState(false);
    const { addToast } = useToast();
    const { can, refresh } = useAuth();

    useEffect(() => {
        api.get('/settings')
            .then((settings) => {
                setForm({ ...INITIAL, ...settings.data });
            })
            .catch(() => addToast('Unable to load settings.', 'error'));
    }, [addToast]);

    const change = (event) => {
        const { name, value, type, checked } = event.target;
        setForm((current) => ({ ...current, [name]: type === 'checkbox' ? checked : value }));
    };

    const toggleDay = (day) => {
        setForm((current) => ({
            ...current,
            default_work_days: current.default_work_days.includes(day)
                ? current.default_work_days.filter((item) => item !== day)
                : [...current.default_work_days, day].sort(),
        }));
    };

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        try {
            const { data } = await api.put('/settings', {
                ...form,
                scan_debounce_seconds: Number(form.scan_debounce_seconds),
                scan_cooldown_seconds: Number(form.scan_cooldown_seconds),
                offline_max_age_hours: Number(form.offline_max_age_hours),
                scan_clock_skew_seconds: Number(form.scan_clock_skew_seconds),
                grace_period_minutes: Number(form.grace_period_minutes),
                default_break_minutes: Number(form.default_break_minutes),
                missed_clock_out_alert_minutes: Number(form.missed_clock_out_alert_minutes),
                absence_alert_minutes: Number(form.absence_alert_minutes),
            });
            setForm({ ...INITIAL, ...data });
            await refresh();
            addToast('Settings saved and applied.', 'success');
        } catch (error) {
            const errors = error.response?.data?.errors;
            const message = errors ? Object.values(errors).flat()[0] : error.response?.data?.message;
            addToast(message || 'Unable to save settings.', 'error');
        } finally {
            setSaving(false);
        }
    };

    const canManage = can('settings.manage');
    const numberField = (name, label, min, max, help) => (
        <label className={fieldLabelClass}>
            {label}
            <input type="number" name={name} min={min} max={max} value={form[name]} onChange={change} required className={inputClass} />
            {help && <span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">{help}</span>}
        </label>
    );

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div><h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">System settings</h1><p className="text-sm text-slate-500 dark:text-slate-400">Every setting below is enforced by the server.</p></div>
            <form onSubmit={submit} className="space-y-7 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                {!canManage && <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900/70 dark:bg-sky-950/40 dark:text-sky-100">You can view the current settings. Only the IT manager or super administrator can change system, network, kiosk, and reporting controls.</div>}
                <fieldset disabled={!canManage} className="space-y-7 disabled:cursor-not-allowed disabled:opacity-70">
                <section className="space-y-4">
                    <h2 className="font-semibold text-slate-900 dark:text-slate-100">Default shift and attendance</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className={fieldLabelClass}>Shift start<TimePicker name="shift_start" value={form.shift_start} onChange={change} required className="mt-1" selectClassName="flex-1" ariaLabel="Shift start" /></label>
                        <label className={fieldLabelClass}>Shift end<TimePicker name="shift_end" value={form.shift_end} onChange={change} required className="mt-1" selectClassName="flex-1" ariaLabel="Shift end" /><span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">An end before start defines an overnight shift.</span></label>
                        {numberField('grace_period_minutes', 'Late-arrival grace (minutes)', 0, 180)}
                        {numberField('default_break_minutes', 'Default unpaid break (minutes)', 0, 480)}
                        {numberField('scan_cooldown_seconds', 'Minimum time before clock-out (seconds)', 0, 43200)}
                    </div>
                    <fieldset><legend className="text-sm font-medium text-slate-700 dark:text-slate-200">Default working days</legend><div className="mt-2 flex flex-wrap gap-3">{DAY_NAMES.map((name, day) => <label key={name} className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" className="accent-hg-blue" checked={form.default_work_days.includes(day)} onChange={() => toggleDay(day)} />{name}</label>)}</div></fieldset>
                </section>

                <section className="space-y-4 border-t pt-6 dark:border-slate-700">
                    <h2 className="font-semibold text-slate-900 dark:text-slate-100">Kiosk and offline scanning</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {numberField('scan_debounce_seconds', 'Duplicate-read window (seconds)', 0, 30, 'Rejects an accidental immediate repeat scan.')}
                        {numberField('offline_max_age_hours', 'Maximum offline event age (hours)', 1, 168)}
                        {numberField('scan_clock_skew_seconds', 'Maximum kiosk clock lead (seconds)', 0, 900, 'Future-dated events beyond this tolerance are rejected.')}
                    </div>
                    <label className={fieldLabelClass}>Branch label<input name="branch_label" value={form.branch_label} onChange={change} required className={inputClass} /></label>
                    <label className={fieldLabelClass}>Allowed scanner IPs/CIDRs<input name="scan_allowed_ips" value={form.scan_allowed_ips} onChange={change} placeholder="192.168.1.0/24, 10.0.0.12" className={inputClass} /><span className="mt-1 block text-xs text-slate-500 dark:text-slate-400">The server applies this global list to every kiosk and biometric request. Blank permits any address; a device may have an additional, tighter list.</span></label>
                </section>

                <section className="space-y-4 border-t pt-6 dark:border-slate-700">
                    <h2 className="font-semibold text-slate-900 dark:text-slate-100">Alerts</h2>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200"><input type="checkbox" className="accent-hg-blue" name="enable_alerts" checked={form.enable_alerts} onChange={change} />Enable attendance alerts</label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {numberField('missed_clock_out_alert_minutes', 'Alert after shift end (minutes)', 0, 1440)}
                        {numberField('absence_alert_minutes', 'Alert after shift start (minutes)', 0, 1440)}
                    </div>
                </section>

                <section className="space-y-4 border-t pt-6 dark:border-slate-700">
                    <h2 className="font-semibold text-slate-900 dark:text-slate-100">Scheduled reports</h2>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200"><input type="checkbox" className="accent-hg-blue" name="enable_scheduled_reports" checked={form.enable_scheduled_reports} onChange={change} />Email attendance reports automatically</label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className={fieldLabelClass}>Recipient<input type="email" name="report_email" value={form.report_email} onChange={change} required={form.enable_scheduled_reports} disabled={!form.enable_scheduled_reports} className={`${inputClass} disabled:opacity-50`} /></label>
                        <label className={fieldLabelClass}>Frequency<select name="report_frequency" value={form.report_frequency} onChange={change} className={inputClass}><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
                    </div>
                </section>

                <section className="space-y-4 border-t pt-6 dark:border-slate-700">
                    <h2 className="font-semibold text-slate-900 dark:text-slate-100">Display</h2>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200"><input type="checkbox" className="accent-hg-blue" name="dark_mode_default" checked={form.dark_mode_default} onChange={change} />Use dark mode by default</label>
                </section>
                </fieldset>

                {canManage && <button type="submit" disabled={saving || form.default_work_days.length === 0} className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500">{saving ? 'Saving…' : 'Save and apply'}</button>}
            </form>
        </div>
    );
}
