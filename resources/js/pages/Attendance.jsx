import React, { useEffect, useState } from 'react';
import api from '../api';

function todayISO() {
    return new Date().toISOString().slice(0, 10);
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
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [departments, setDepartments] = useState([]);
    const [staffOpts, setStaffOpts] = useState([]);
    const [editing, setEditing] = useState(null);
    const [editForm, setEditForm] = useState({ clock_in: '', clock_out: '', notes: '' });
    const [saving, setSaving] = useState(false);
    const [filters, setFilters] = useState({
        date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        date_to: todayISO(),
        department: '',
        staff_pk: '',
        status: '',
    });

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
    }, []);

    const load = (page = 1) => {
        const params = {
            page,
            date_from: filters.date_from,
            date_to: filters.date_to,
            department: filters.department || undefined,
            staff_pk: filters.staff_pk || undefined,
            status: filters.status || undefined,
        };
        api.get('/attendances', { params }).then((r) => {
            setRows(r.data.data);
            setMeta({ current_page: r.data.current_page, last_page: r.data.last_page });
        });
    };

    useEffect(() => {
        load(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const change = (e) => {
        const { name, value } = e.target;
        setFilters((f) => ({ ...f, [name]: value }));
    };

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
            window.alert(msg);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Attendance log</h1>
                <p className="text-sm text-slate-500">Filter records or edit clock-in / clock-out times.</p>
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3 lg:grid-cols-6">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input
                        type="date"
                        name="date_from"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.date_from}
                        onChange={change}
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input
                        type="date"
                        name="date_to"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.date_to}
                        onChange={change}
                    />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Department
                    <select
                        name="department"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.department}
                        onChange={change}
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
                    <select
                        name="staff_pk"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.staff_pk}
                        onChange={change}
                    >
                        <option value="">Any</option>
                        {staffOpts.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.full_name} ({s.staff_id})
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Status
                    <select
                        name="status"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm"
                        value={filters.status}
                        onChange={change}
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
                                    {a.clock_in
                                        ? new Date(a.clock_in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                                        : ''}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {a.clock_out
                                        ? new Date(a.clock_out).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">{a.total_hours ?? '—'}</td>
                                <td className="px-3 py-2">{a.is_late ? `${a.late_minutes}m` : '—'}</td>
                                <td className="px-3 py-2">{a.overtime_minutes ? `${a.overtime_minutes}m` : '—'}</td>
                                <td className="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        onClick={() => openEdit(a)}
                                        className="text-xs font-semibold text-sky-700 underline"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex items-center justify-between text-sm text-slate-600">
                <span>
                    Page {meta.current_page} of {meta.last_page}
                </span>
                <div className="flex gap-2">
                    <button
                        type="button"
                        disabled={meta.current_page <= 1}
                        className="rounded-lg border px-3 py-1 disabled:opacity-40"
                        onClick={() => load(meta.current_page - 1)}
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        disabled={meta.current_page >= meta.last_page}
                        className="rounded-lg border px-3 py-1 disabled:opacity-40"
                        onClick={() => load(meta.current_page + 1)}
                    >
                        Next
                    </button>
                </div>
            </div>

            {editing && (
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
                            <input
                                type="datetime-local"
                                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                value={editForm.clock_in}
                                onChange={(e) => setEditForm((f) => ({ ...f, clock_in: e.target.value }))}
                                required
                            />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Clock out
                            <input
                                type="datetime-local"
                                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                value={editForm.clock_out}
                                onChange={(e) => setEditForm((f) => ({ ...f, clock_out: e.target.value }))}
                            />
                        </label>

                        <label className="mt-4 block text-sm font-medium text-slate-700">
                            Notes
                            <textarea
                                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                rows={3}
                                value={editForm.notes}
                                onChange={(e) => setEditForm((f) => ({ ...f, notes: e.target.value }))}
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
        </div>
    );
}
