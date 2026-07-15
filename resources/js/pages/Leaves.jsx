import React, { useEffect, useState } from 'react';
import api from '../api';

const EMPTY = {
    staff_id: '',
    start_date: '',
    end_date: '',
    type: 'Annual',
    reason: '',
    status: 'Pending',
};

export default function Leaves() {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [filters, setFilters] = useState({
        date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        date_to: new Date().toISOString().slice(0, 10),
        status: '',
        department: '',
    });
    const [departments, setDepartments] = useState([]);
    const [staffOpts, setStaffOpts] = useState([]);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
    }, []);

    const load = (page = 1) => {
        const params = {
            page,
            date_from: filters.date_from,
            date_to: filters.date_to,
            status: filters.status || undefined,
            department: filters.department || undefined,
        };
        api.get('/leaves', { params }).then((r) => {
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

    const openNew = () => {
        setEditing('new');
        setForm({
            ...EMPTY,
            staff_id: '',
            start_date: new Date().toISOString().slice(0, 10),
            end_date: new Date().toISOString().slice(0, 10),
        });
    };

    const openEdit = (row) => {
        setEditing(row.id);
        setForm({
            staff_id: row.staff_id,
            start_date: row.start_date,
            end_date: row.end_date,
            type: row.type,
            reason: row.reason ?? '',
            status: row.status,
        });
    };

    const closeEdit = () => {
        setEditing(null);
        setForm(EMPTY);
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            const payload = {
                ...form,
                staff_id: form.staff_id ? Number(form.staff_id) : '',
            };
            if (editing === 'new') {
                await api.post('/leaves', payload);
            } else {
                await api.put(`/leaves/${editing}`, payload);
            }
            closeEdit();
            load(meta.current_page);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Unable to save leave';
            window.alert(msg);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Leaves</h1>
                    <p className="text-sm text-slate-500">Track approved, pending, and rejected leave.</p>
                </div>
                <button type="button" onClick={openNew} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
                    Add leave
                </button>
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input type="date" name="date_from" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_from} onChange={change} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input type="date" name="date_to" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_to} onChange={change} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Status
                    <select name="status" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.status} onChange={change}>
                        <option value="">All</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Department
                    <select name="department" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.department} onChange={change}>
                        <option value="">All</option>
                        {departments.map((d) => (
                            <option key={d} value={d}>{d}</option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="flex justify-end">
                <button type="button" onClick={() => load(1)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
                    Apply
                </button>
            </div>

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Staff</th>
                            <th className="px-3 py-2">Start</th>
                            <th className="px-3 py-2">End</th>
                            <th className="px-3 py-2">Type</th>
                            <th className="px-3 py-2">Reason</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-3 py-2">
                                    <div className="font-semibold text-slate-900">{r.staff?.full_name}</div>
                                    <div className="font-mono text-xs text-slate-500">{r.staff?.staff_id}</div>
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">{r.start_date}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{r.end_date}</td>
                                <td className="px-3 py-2">{r.type}</td>
                                <td className="px-3 py-2 max-w-xs truncate">{r.reason}</td>
                                <td className="px-3 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${r.status === 'Approved' ? 'bg-emerald-50 text-emerald-800' : r.status === 'Rejected' ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-800'}`}>
                                        {r.status}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <button type="button" onClick={() => openEdit(r)} className="text-xs font-semibold text-sky-700 underline">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex items-center justify-between text-sm text-slate-600">
                <span>Page {meta.current_page} of {meta.last_page}</span>
                <div className="flex gap-2">
                    <button type="button" disabled={meta.current_page <= 1} className="rounded-lg border px-3 py-1 disabled:opacity-40" onClick={() => load(meta.current_page - 1)}>Previous</button>
                    <button type="button" disabled={meta.current_page >= meta.last_page} className="rounded-lg border px-3 py-1 disabled:opacity-40" onClick={() => load(meta.current_page + 1)}>Next</button>
                </div>
            </div>

            {(editing === 'new' || editing) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={submit} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-bold text-slate-900">{editing === 'new' ? 'Add leave' : 'Edit leave'}</h2>
                        <div className="mt-4 space-y-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Staff
                                <select name="staff_id" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.staff_id} onChange={(e) => setForm((f) => ({ ...f, staff_id: e.target.value }))} required>
                                    <option value="">Select staff</option>
                                    {staffOpts.map((s) => (
                                        <option key={s.id} value={s.id}>{s.full_name} ({s.staff_id})</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Start date
                                <input type="date" name="start_date" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.start_date} onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))} required />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                End date
                                <input type="date" name="end_date" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.end_date} onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))} required />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Type
                                <select name="type" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
                                    <option value="Annual">Annual</option>
                                    <option value="Sick">Sick</option>
                                    <option value="Maternity">Maternity</option>
                                    <option value="Paternity">Paternity</option>
                                    <option value="Emergency">Emergency</option>
                                    <option value="Unpaid">Unpaid</option>
                                    <option value="Other">Other</option>
                                </select>
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Reason
                                <textarea name="reason" rows={3} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} />
                            </label>
                            {editing !== 'new' && (
                                <label className="block text-sm font-medium text-slate-700">
                                    Status
                                    <select name="status" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.status} onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}>
                                        <option value="Pending">Pending</option>
                                        <option value="Approved">Approved</option>
                                        <option value="Rejected">Rejected</option>
                                    </select>
                                </label>
                            )}
                        </div>
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={closeEdit} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Cancel</button>
                            <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{saving ? 'Saving…' : 'Save'}</button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
