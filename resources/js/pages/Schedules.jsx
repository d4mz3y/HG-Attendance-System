import React, { useEffect, useState } from 'react';
import api from '../api';
import StaffPicker from '../components/StaffPicker';
import TimePicker from '../components/TimePicker';
import { useToast } from '../components/Toast';
import { useAuth } from '../AuthContext';
import { formatTime } from '../timeFormat';

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const EMPTY_SCHEDULE = {
    day_of_week: 0,
    shift_start: '',
    shift_end: '',
    break_minutes: 60,
    is_day_off: false,
    works_on_public_holiday: false,
};

const comparable = (schedule) => JSON.stringify({
    shift_start: schedule?.shift_start ?? null,
    shift_end: schedule?.shift_end ?? null,
    break_minutes: Number(schedule?.break_minutes ?? 0),
    is_day_off: Boolean(schedule?.is_day_off),
    works_on_public_holiday: Boolean(schedule?.works_on_public_holiday),
});

function mergeWithDefaults(explicit, defaults) {
    return defaults.map((fallback) => {
        const override = explicit.find((item) => Number(item.day_of_week) === Number(fallback.day_of_week));
        return override ?? fallback;
    });
}

function aggregateDepartment(members, defaults) {
    if (!members.length) return [];

    return defaults.map((fallback) => {
        const effective = members.map((member) => (
            member.schedules?.find((item) => Number(item.day_of_week) === Number(fallback.day_of_week)) ?? fallback
        ));
        const mixed = new Set(effective.map(comparable)).size > 1;

        return mixed
            ? { ...fallback, id: `department-${fallback.day_of_week}`, mixed: true, inherited: false }
            : { ...effective[0], id: `department-${fallback.day_of_week}`, mixed: false };
    });
}

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
    const [defaults, setDefaults] = useState([]);
    const [departmentMemberCount, setDepartmentMemberCount] = useState(0);
    const { addToast } = useToast();
    const { can } = useAuth();
    const canManage = can('schedule.manage');

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaff(r.data));
        api.get('/schedules/defaults').then((r) => {
            const nextDefaults = Array.isArray(r.data) ? r.data : [];
            setDefaults(nextDefaults);
            if (nextDefaults[0]) setForm(nextDefaults[0]);
        });
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
                if (type === 'department') {
                    const members = Array.isArray(r.data) ? r.data : [];
                    setDepartmentMemberCount(members.length);
                    setSchedules(aggregateDepartment(members, defaults));
                    setSelectedDepartment(id);
                } else {
                    const explicit = Array.isArray(r.data) ? r.data : [];
                    setDepartmentMemberCount(0);
                    setSchedules(mergeWithDefaults(explicit, defaults));
                    setSelectedStaff(id);
                }
            })
            .finally(() => setLoading(false));
    };

    const change = (e) => {
        const { name, value, type, checked } = e.target;
        setForm((f) => ({ ...f, [name]: type === 'checkbox' ? checked : value }));
    };

    const openNew = () => {
        setForm(defaults[0] ?? EMPTY_SCHEDULE);
    };

    const editSchedule = (item) => {
        const source = item.mixed
            ? (defaults.find((fallback) => Number(fallback.day_of_week) === Number(item.day_of_week)) ?? item)
            : item;
        setForm({
            day_of_week: source.day_of_week,
            shift_start: source.shift_start || '',
            shift_end: source.shift_end || '',
            break_minutes: source.break_minutes,
            is_day_off: source.is_day_off,
            works_on_public_holiday: source.works_on_public_holiday || false,
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
            setForm(defaults[0] ?? EMPTY_SCHEDULE);
        } catch (err) {
            addToast(err.response?.data?.message ?? 'Unable to save schedule', 'error');
        } finally {
            setSaving(false);
        }
    };

    const resetToDefaults = async () => {
        const targetId = mode === 'department' ? selectedDepartment : selectedStaff;
        if (! targetId) {
            return;
        }
        setSaving(true);
        try {
            const endpoint = mode === 'department'
                ? `/schedules/department/${encodeURIComponent(targetId)}`
                : `/schedules/${targetId}`;
            await api.delete(endpoint);
            loadSchedules(targetId, mode);
            addToast('Explicit schedule rules removed; current system defaults now apply.', 'success');
        } catch (err) {
            addToast(err.response?.data?.message ?? 'Unable to save schedule', 'error');
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
                        <StaffPicker
                            className="mt-1"
                            options={staff}
                            value={selectedStaff}
                            onChange={(value) => loadSchedules(value, 'staff')}
                            emptyLabel="Choose a staff member"
                        />
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
                    {canManage && <div className="flex justify-end gap-2">
                        <button type="button" onClick={openNew} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Add rule</button>
                        <button type="button" onClick={resetToDefaults} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Use system defaults</button>
                    </div>}

                    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Day</th>
                                    <th className="px-3 py-2">Shift start</th>
                                    <th className="px-3 py-2">Shift end</th>
                                    <th className="px-3 py-2">Break (min)</th>
                                    <th className="px-3 py-2">Day off</th>
                                    <th className="px-3 py-2">Works on public holiday</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {loading ? (
                                    <tr><td colSpan={7} className="px-3 py-4 text-center text-slate-500">Loading…</td></tr>
                                ) : schedules.length === 0 ? (
                                    <tr><td colSpan={7} className="px-3 py-4 text-center text-slate-500">No schedules configured.</td></tr>
                                ) : schedules.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50">
                                        <td className="px-3 py-2 font-medium">{s.day_name}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{s.mixed ? 'Mixed' : formatTime(s.shift_start)}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{s.mixed ? 'Mixed' : formatTime(s.shift_end)}</td>
                                        <td className="px-3 py-2">{s.mixed ? 'Mixed' : s.break_minutes}</td>
                                        <td className="px-3 py-2">{s.mixed ? 'Mixed' : (s.is_day_off ? 'Yes' : 'No')}</td>
                                        <td className="px-3 py-2">{s.mixed ? 'Mixed' : (s.works_on_public_holiday ? 'Yes' : 'No')}</td>
                                        <td className="px-3 py-2 text-right">
                                            {canManage && <button type="button" onClick={() => editSchedule(s)} className="text-xs font-semibold text-sky-700 underline">{s.mixed ? 'Set for all' : 'Edit'}</button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                            {canManage && (form.day_of_week !== undefined) && (
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
                                            <TimePicker name="shift_start" value={form.shift_start} onChange={change} disabled={form.is_day_off} allowEmpty className="mt-1" selectClassName="flex-1" ariaLabel="Shift start" />
                                        </label>
                                        <label className="block text-sm font-medium text-slate-700">
                                            Shift end
                                            <TimePicker name="shift_end" value={form.shift_end} onChange={change} disabled={form.is_day_off} allowEmpty className="mt-1" selectClassName="flex-1" ariaLabel="Shift end" />
                                        </label>
                                        <label className="block text-sm font-medium text-slate-700">
                                            Break (minutes)
                                            <input type="number" name="break_minutes" min={0} max={480} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.break_minutes} onChange={change} disabled={form.is_day_off} />
                                        </label>
                                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="checkbox" name="is_day_off" checked={form.is_day_off} onChange={change} />
                                            Day off
                                        </label>
                                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="checkbox" name="works_on_public_holiday" checked={form.works_on_public_holiday} onChange={change} disabled={form.is_day_off} />
                                            Works on public holiday
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
