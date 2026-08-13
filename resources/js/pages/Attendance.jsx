import React, { useEffect, useState } from 'react';
import api from '../api';
import { usePaginatedTable } from '../hooks/usePaginatedTable';
import Paginator from '../components/Paginator';
import StaffPicker from '../components/StaffPicker';
import DateTimePicker from '../components/DateTimePicker';
import { useToast } from '../components/Toast';
import { useAuth } from '../AuthContext';
import { localDateISO } from '../localDate';
import { formatTime } from '../timeFormat';

function todayISO() {
    return localDateISO();
}

function toDatetimeLocal(iso) {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export default function Attendance() {
    const [departments, setDepartments] = useState([]);
    const [staffOpts, setStaffOpts] = useState([]);
    const [editing, setEditing] = useState(null);
    const [editForm, setEditForm] = useState({ clock_in: '', clock_out: '', notes: '' });
    const [saving, setSaving] = useState(false);
    const [showManual, setShowManual] = useState(false);
    const [manualForm, setManualForm] = useState({ staff_id: '', date: todayISO(), clock_in: '', clock_out: '', notes: '', break_minutes: '' });
    const [manualSaving, setManualSaving] = useState(false);
    const { addToast } = useToast();
    const { can } = useAuth();
    const canManage = can('attendance.manage');

    const { rows, meta, filters, loading, load, updateFilter } = usePaginatedTable('/attendances', {
        date_from: localDateISO(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
        date_to: todayISO(),
        department: '',
        staff_pk: '',
        status: '',
    });

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
    }, []);

    const openEdit = (row) => {
        setEditing(row);
        setEditForm({
            clock_in: toDatetimeLocal(row.clock_in),
            clock_out: toDatetimeLocal(row.clock_out),
            notes: row.notes ?? '',
        });
    };

    const closeEdit = () => {
        setEditing(null);
    };

    const saveEdit = async (e) => {
        e.preventDefault();
        if (!editing) {
            return;
        }
        setSaving(true);
        try {
            await api.patch(`/attendances/${editing.id}`, {
                clock_in: editForm.clock_in,
                clock_out: editForm.clock_out || null,
                notes: editForm.notes,
            });
            closeEdit();
            load(meta.current_page);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Unable to save attendance';
            addToast(msg, 'error');
        } finally {
            setSaving(false);
        }
    };

    const saveManual = async (e) => {
        e.preventDefault();
        if (!manualForm.staff_id) {
            addToast('Choose a staff member before saving attendance.', 'error');

            return;
        }
        setManualSaving(true);
        try {
            await api.post('/attendances/manual', {
                staff_id: Number(manualForm.staff_id),
                date: manualForm.date,
                clock_in: manualForm.clock_in || null,
                clock_out: manualForm.clock_out || null,
                notes: manualForm.notes || null,
                break_minutes: manualForm.break_minutes === '' ? null : Number(manualForm.break_minutes),
            });
            setShowManual(false);
            setManualForm({ staff_id: '', date: todayISO(), clock_in: '', clock_out: '', notes: '', break_minutes: '' });
            load(meta.current_page);
            addToast('Attendance record created', 'success');
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Unable to create attendance';
            addToast(msg, 'error');
        } finally {
            setManualSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Attendance log</h1>
                <p className="text-sm text-slate-500">Filter records or edit clock-in / clock-out times.</p>
            </div>

            {canManage && (
                <div className="flex justify-end">
                    <button type="button" onClick={() => setShowManual(true)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Mark attendance manually</button>
                </div>
            )}

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3 lg:grid-cols-6">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input
                        type="date"
                        name="date_from"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.date_from}
                        onChange={(e) => updateFilter('date_from', e.target.value)}
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input
                        type="date"
                        name="date_to"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.date_to}
                        onChange={(e) => updateFilter('date_to', e.target.value)}
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Department
                    <select
                        name="department"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.department}
                        onChange={(e) => updateFilter('department', e.target.value)}
                    >
                        <option value="">Any</option>
                        {departments.map((d) => (
                            <option key={d} value={d}>
                                {d}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Staff
                    <StaffPicker
                        name="staff_pk"
                        className="mt-1"
                        options={staffOpts}
                        value={filters.staff_pk}
                        onChange={(value) => updateFilter('staff_pk', value)}
                        emptyLabel="Any staff"
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Status
                    <select
                        name="status"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.status}
                        onChange={(e) => updateFilter('status', e.target.value)}
                    >
                        <option value="">All</option>
                        <option value="late">Late</option>
                        <option value="on_time">On time</option>
                        <option value="overtime">Overtime</option>
                        <option value="incomplete">Incomplete</option>
                    </select>
                </label>
                <div className="flex items-end">
                    <button
                        type="button"
                        onClick={() => load(1)}
                        className="w-full rounded-lg bg-slate-900 py-2 text-sm font-semibold text-white"
                    >
                        Apply
                    </button>
                </div>
            </div>

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Date</th>
                            <th className="px-3 py-2">Staff</th>
                            <th className="px-3 py-2">Dept</th>
                            <th className="px-3 py-2">In</th>
                            <th className="px-3 py-2">Out</th>
                            <th className="px-3 py-2">Hours</th>
                            <th className="px-3 py-2">Late</th>
                            <th className="px-3 py-2">OT</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((a) => (
                            <tr key={a.id} className="hover:bg-slate-50">
                                <td className="px-3 py-2 whitespace-nowrap">{a.date}</td>
                                <td className="px-3 py-2">
                                    <div className="font-semibold text-slate-900">{a.staff?.full_name}</div>
                                    <div className="font-mono text-xs text-slate-500">{a.staff?.staff_id}</div>
                                </td>
                                <td className="px-3 py-2">{a.staff?.department}</td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {formatTime(a.clock_in, '')}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {formatTime(a.clock_out)}
                                </td>
                                <td className="px-3 py-2">{a.total_hours ?? '—'}</td>
                                <td className="px-3 py-2">{a.is_late ? `${a.late_minutes}m` : '—'}</td>
                                <td className="px-3 py-2">{a.overtime_minutes ? `${a.overtime_minutes}m` : '—'}</td>
                                <td className="px-3 py-2 text-right">
                                    {canManage && <button
                                        type="button"
                                        onClick={() => openEdit(a)}
                                        className="text-xs font-semibold text-sky-700 underline"
                                    >
                                        Edit
                                    </button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Paginator meta={meta} onPage={load} />

            {canManage && editing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form
                        onSubmit={saveEdit}
                        className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
                    >
                        <h2 className="text-lg font-bold text-slate-900">Edit attendance</h2>
                        <p className="mt-1 text-sm text-slate-600">
                            {editing.staff?.full_name} · {editing.staff?.staff_id}
                        </p>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Clock in
                            <DateTimePicker id="clock_in" name="clock_in" className="mt-1" value={editForm.clock_in} onChange={(e) => setEditForm((f) => ({ ...f, clock_in: e.target.value }))} required ariaLabel="Clock in" />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Clock out
                            <DateTimePicker id="clock_out" name="clock_out" className="mt-1" value={editForm.clock_out} onChange={(e) => setEditForm((f) => ({ ...f, clock_out: e.target.value }))} ariaLabel="Clock out" />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            HR Comment / Notes
                            <textarea
                                id="notes"
                                name="notes"
                                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                rows={3}
                                value={editForm.notes}
                                onChange={(e) => setEditForm((f) => ({ ...f, notes: e.target.value }))}
                                placeholder="Add a comment for this attendance record..."
                            />
                        </label>

                        <p className="mt-3 text-xs text-slate-500">
                            Late minutes, overtime, and total hours are recalculated from shift settings.
                        </p>

                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={closeEdit}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={saving}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {saving ? 'Saving…' : 'Save changes'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
            {canManage && showManual && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={saveManual} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-bold text-slate-900">Mark attendance manually</h2>
                        <p className="mt-1 text-sm text-slate-600">Create or override an attendance record.</p>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Staff
                            <StaffPicker
                                name="staff_id"
                                className="mt-1"
                                options={staffOpts}
                                value={manualForm.staff_id}
                                onChange={(value) => setManualForm((form) => ({ ...form, staff_id: value }))}
                                emptyLabel="Choose staff"
                                required
                            />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Date
                            <input type="date" name="date" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={manualForm.date} onChange={(e) => setManualForm((f) => ({ ...f, date: e.target.value }))} required />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Clock in
                            <DateTimePicker name="clock_in" className="mt-1" value={manualForm.clock_in} onChange={(e) => setManualForm((f) => ({ ...f, clock_in: e.target.value }))} ariaLabel="Clock in" />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Clock out
                            <DateTimePicker name="clock_out" className="mt-1" value={manualForm.clock_out} onChange={(e) => setManualForm((f) => ({ ...f, clock_out: e.target.value }))} ariaLabel="Clock out" />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Break (minutes)
                            <input type="number" name="break_minutes" min={0} max={480} placeholder="Use scheduled/default break" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={manualForm.break_minutes} onChange={(e) => setManualForm((f) => ({ ...f, break_minutes: e.target.value }))} />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            HR Comment / Notes
                            <textarea name="notes" rows={3} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={manualForm.notes} onChange={(e) => setManualForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Reason for manual override..." />
                        </label>

                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={() => setShowManual(false)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Cancel</button>
                            <button type="submit" disabled={manualSaving} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{manualSaving ? 'Saving…' : 'Save'}</button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
