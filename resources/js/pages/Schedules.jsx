import React, { useEffect, useState } from 'react';
import api from '../api';

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const EMPTY_SCHEDULE = {
    day_of_week: 0,
    shift_start: '',
    shift_end: '',
    break_minutes: 60,
    is_day_off: false,
};

export default function Schedules() {
    const [staff, setStaff] = useState([]);
    const [departments, setDepartments] = useState([]);
    const [selectedStaff, setSelectedStaff] = useState('');
    const [selectedDepartment, setSelectedDepartment] = useState('');
    const [schedules, setSchedules] = useState([]);
    const [form, setForm] = useState(EMPTY_SCHEDULE);
    const [saving, setSaving] = useState(false);
    const [loading, setLoading] = useState(false);
    const [mode, setMode] = useState('staff');

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaff(r.data));
    }, []);

    const loadSchedules = (id, type = 'staff') => {
        if (! id) {
            setSchedules([]);
            return;
        }
        setLoading(true);
        const endpoint = type === 'department'
            ? `/schedules/department/${encodeURIComponent(id)}`
            : `/schedules/${id}`;
        api.get(endpoint)
            .then((r) => {
                setSchedules(r.data);
                if (type === 'staff') setSelectedStaff(id);
                if (type === 'department') setSelectedDepartment(id);
            })
            .finally(() => setLoading(false));
    };

    const change = (e) => {
        const { name, value, type, checked } = e.target;
        setForm((f) => ({ ...f, [name]: type === 'checkbox' ? checked : value }));
    };

    const openNew = () => {
        setForm(EMPTY_SCHEDULE);
    };

    const editSchedule = (item) => {
        setForm({
            day_of_week: item.day_of_week,
            shift_start: item.shift_start || '',
            shift_end: item.shift_end || '',
            break_minutes: item.break_minutes,
            is_day_off: item.is_day_off,
        });
    };

    const submit = async (e) => {
        e.preventDefault();
        const targetId = mode === 'department' ? selectedDepartment : selectedStaff;
        if (! targetId) {
            return;
        }
        setSaving(true);
        try {
            const payload = { schedules: [form] };
            const endpoint = mode === 'department'
                ? `/schedules/department/${encodeURIComponent(targetId)}`
                : `/schedules/${targetId}`;
            await api.put(endpoint, payload);
            loadSchedules(targetId, mode);
            setForm(EMPTY_SCHEDULE);
        } catch (err) {
            window.alert(err.response?.data?.message ?? 'Unable to save schedule');
        } finally {
            setSaving(false);
        }
    };

    const bulkSave = async () => {
        const targetId = mode === 'department' ? selectedDepartment : selectedStaff;
        if (! targetId) {
            return;
        }
        const defaultSchedule = {
            day_of_week: 1,
            shift_start: '08:00',
            shift_end: '17:00',
            break_minutes: 60,
            is_day_off: false,
        };
        const items = DAY_NAMES.map((_, i) => ({
            day_of_week: i,
            shift_start: defaultSchedule.shift_start,
            shift_end: defaultSchedule.shift_end,
            break_minutes: 60,
            is_day_off: i === 0 || i === 6,
        }));

        setSaving(true);
        try {
            const endpoint = mode === 'department'
                ? `/schedules/department/${encodeURIComponent(targetId)}`
                : `/schedules/${targetId}`;
            await api.put(endpoint, { schedules: items });
            loadSchedules(targetId, mode);
        } catch (err) {
            window.alert(err.response?.data?.message ?? 'Unable to save schedule');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Schedules</h1>
                <p className="text-sm text-slate-500">Per-staff or per-department shift schedules and day-off rules.</p>
            </div>

            <div className="flex gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <button type="button" onClick={() => setMode('staff')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${mode === 'staff' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700'}`}>
                    By Staff
                </button>
                <button type="button" onClick={() => setMode('department')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${mode === 'department' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700'}`}>
                    By Department
                </button>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                {mode === 'staff' ? (
                    <label className="block text-sm font-medium text-slate-700">
                        Select staff
                        <select className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={selectedStaff} onChange={(e) => loadSchedules(e.target.value, 'staff')}>
                            <option value="">Choose a staff member</option>
                            {staff.map((s) => (
                                <option key={s.id} value={s.id}>{s.full_name} ({s.staff_id})</option>
                            ))}
                        </select>
                    </label>
                ) : (
                    <label className="block text-sm font-medium text-slate-700">
                        Select department
                        <select className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={selectedDepartment} onChange={(e) => loadSchedules(e.target.value, 'department')}>
                            <option value="">Choose a department</option>
                            {departments.map((d) => (
                                <option key={d} value={d}>{d}</option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {(selectedStaff || selectedDepartment) && (
                <>
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={openNew} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Add rule</button>
                        <button type="button" onClick={bulkSave} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Reset to default</button>
                    </div>

                    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Day</th>
                                    <th className="px-3 py-2">Shift start</th>
                                    <th className="px-3 py-2">Shift end</th>
                                    <th className="px-3 py-2">Break (min)</th>
                                    <th className="px-3 py-2">Day off</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {loading ? (
                                    <tr><td colSpan={6} className="px-3 py-4 text-center text-slate-500">Loading…</td></tr>
                                ) : schedules.length === 0 ? (
                                    <tr><td colSpan={6} className="px-3 py-4 text-center text-slate-500">No schedules configured.</td></tr>
                                ) : schedules.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50">
                                        <td className="px-3 py-2 font-medium">{s.day_name}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{s.shift_start || '—'}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{s.shift_end || '—'}</td>
                                        <td className="px-3 py-2">{s.break_minutes}</td>
                                        <td className="px-3 py-2">{s.is_day_off ? 'Yes' : 'No'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button type="button" onClick={() => editSchedule(s)} className="text-xs font-semibold text-sky-700 underline">Edit</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {(form.day_of_week !== undefined) && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-lg font-bold text-slate-900">{schedules.find((s) => s.day_of_week === form.day_of_week) ? 'Edit rule' : 'Add rule'}</h3>
                            <form onSubmit={submit} className="mt-4 grid gap-4 sm:grid-cols-2">
                                <label className="block text-sm font-medium text-slate-700">
                                    Day
                                    <select name="day_of_week" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.day_of_week} onChange={change}>
                                        {DAY_NAMES.map((name, i) => (
                                            <option key={i} value={i}>{name}</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Shift start
                                    <input type="time" name="shift_start" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.shift_start} onChange={change} />
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Shift end
                                    <input type="time" name="shift_end" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.shift_end} onChange={change} />
                                </label>
                                <label className="block text-sm font-medium text-slate-700">
                                    Break (minutes)
                                    <input type="number" name="break_minutes" min={0} max={480} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.break_minutes} onChange={change} />
                                </label>
                                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" name="is_day_off" checked={form.is_day_off} onChange={change} />
                                    Day off
                                </label>
                                <div className="sm:col-span-2 flex justify-end gap-2 pt-2">
                                    <button type="button" onClick={() => setForm(EMPTY_SCHEDULE)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Cancel</button>
                                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{saving ? 'Saving…' : 'Save'}</button>
                                </div>
                            </form>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
