import React, { useEffect, useState } from 'react';
import api from '../api';
import { useToast, ConfirmDialog } from '../components/Toast';
import { usePaginatedTable } from '../hooks/usePaginatedTable';
import Paginator from '../components/Paginator';

function todayISO() {
    return new Date().toISOString().slice(0, 10);
}

function formatDate(date) {
    if (!date) return '';
    if (Array.isArray(date)) return date.join(' - ');
    const d = new Date(date);
    if (isNaN(d.getTime())) return String(date).slice(0, 10);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export default function PublicHolidays() {
    const [tab, setTab] = useState('holidays');
    const [holidays, setHolidays] = useState([]);
    const [form, setForm] = useState({ date: '', name: '', description: '', is_recurring: false });
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [year, setYear] = useState(new Date().getFullYear());
    const { addToast } = useToast();
    const [confirmState, setConfirmState] = useState({ open: false, title: '', message: '', onConfirm: () => {} });

    const [departments, setDepartments] = useState([]);
    const [staffOpts, setStaffOpts] = useState([]);
    const [selectedHoliday, setSelectedHoliday] = useState('');

    const { rows, meta, filters, loading, load, updateFilter } = usePaginatedTable('/reports', {
        date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        date_to: todayISO(),
        department: '',
        staff_pk: '',
        status: 'public_holiday_work',
    });

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
    }, []);

    useEffect(() => {
        api.get('/public-holidays', { params: { year } }).then((r) => setHolidays(r.data));
    }, [year]);

    useEffect(() => {
        if (selectedHoliday) {
            updateFilter('date_from', selectedHoliday);
            updateFilter('date_to', selectedHoliday);
        }
    }, [selectedHoliday]);

    const clearHolidayFilter = () => {
        setSelectedHoliday('');
        updateFilter('date_from', new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10));
        updateFilter('date_to', todayISO());
    };

    const change = (e) => {
        const { name, value, type, checked } = e.target;
        setForm((f) => ({ ...f, [name]: type === 'checkbox' ? checked : value }));
    };

    const openNew = () => {
        setEditing('new');
        setForm({ date: new Date().toISOString().slice(0, 10), name: '', description: '', is_recurring: false });
    };

    const openEdit = (holiday) => {
        setEditing(holiday.id);
        setForm({
            date: holiday.date,
            name: holiday.name,
            description: holiday.description ?? '',
            is_recurring: holiday.is_recurring,
        });
    };

    const closeEdit = () => {
        setEditing(null);
        setForm({ date: '', name: '', description: '', is_recurring: false });
    };

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        try {
            if (editing === 'new') {
                await api.post('/public-holidays', form);
            } else {
                await api.put(`/public-holidays/${editing}`, form);
            }
            closeEdit();
            loadHolidays();
        } catch (err) {
            addToast(err.response?.data?.message ?? 'Unable to save holiday', 'error');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (id) => {
        setConfirmState({ open: true, title: 'Confirm', message: 'Remove this holiday?', onConfirm: async () => {
            await api.delete(`/public-holidays/${id}`);
            loadHolidays();
        }});
    };

    const loadHolidays = () => {
        api.get('/public-holidays', { params: { year } }).then((r) => setHolidays(r.data));
    };

    return (
        <div className="mx-auto max-w-5xl space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Public holidays</h1>
                    <p className="text-sm text-slate-500">Mark company-wide holidays and track staff who worked on them.</p>
                </div>
                <div className="flex gap-2">
                    <select className="rounded-lg border border-slate-300 px-3 py-2 text-sm" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                        {[2024, 2025, 2026, 2027, 2028].map((y) => <option key={y} value={y}>{y}</option>)}
                    </select>
                    {tab === 'holidays' && (
                        <button type="button" onClick={openNew} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Add holiday</button>
                    )}
                </div>
            </div>

            <div className="flex gap-2">
                <button type="button" onClick={() => setTab('holidays')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'holidays' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700'}`}>
                    Holidays
                </button>
                <button type="button" onClick={() => setTab('work')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'work' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-700'}`}>
                    Holiday Work
                </button>
            </div>

            {tab === 'holidays' && (
                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Date</th>
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Recurring</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {holidays.map((h) => (
                                <tr key={h.id} className="hover:bg-slate-50">
                                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(h.date)}</td>
                                    <td className="px-3 py-2 font-medium">{h.name}</td>
                                    <td className="px-3 py-2">{h.is_recurring ? 'Yes' : 'No'}</td>
                                    <td className="px-3 py-2 text-right">
                                        <button type="button" onClick={() => openEdit(h)} className="text-xs font-semibold text-sky-700 underline">Edit</button>
                                        <button type="button" onClick={() => remove(h.id)} className="ml-3 text-xs font-semibold text-red-600">Remove</button>
                                    </td>
                                </tr>
                            ))}
                            {holidays.length === 0 && (
                                <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-500">No holidays found.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {tab === 'work' && (
                <div className="space-y-4">
                    <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4">
                        <label className="text-xs font-semibold text-slate-600">
                            Public Holiday
                            <select className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={selectedHoliday} onChange={(e) => setSelectedHoliday(e.target.value)}>
                                <option value="">All holidays</option>
                                {holidays.map((h) => <option key={h.id} value={formatDate(h.date)}>{formatDate(h.date)} - {h.name}</option>)}
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">
                            Department
                            <select name="department" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.department} onChange={(e) => updateFilter('department', e.target.value)}>
                                <option value="">Any</option>
                                {departments.map((d) => <option key={d} value={d}>{d}</option>)}
                            </select>
                        </label>
                        <label className="text-xs font-semibold text-slate-600">
                            Staff
                            <select name="staff_pk" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.staff_pk} onChange={(e) => updateFilter('staff_pk', e.target.value)}>
                                <option value="">Any</option>
                                {staffOpts.map((s) => <option key={s.id} value={s.id}>{s.full_name} ({s.staff_id})</option>)}
                            </select>
                        </label>
                        <div className="flex items-end gap-2">
                            <button type="button" onClick={() => load(1)} className="w-full rounded-lg bg-slate-900 py-2 text-sm font-semibold text-white">Run report</button>
                            {selectedHoliday && (
                                <button type="button" onClick={clearHolidayFilter} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-800">Clear</button>
                            )}
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Name</th>
                                    <th className="px-3 py-2">Staff ID</th>
                                    <th className="px-3 py-2">Dept</th>
                                    <th className="px-3 py-2">Date</th>
                                    <th className="px-3 py-2">In</th>
                                    <th className="px-3 py-2">Out</th>
                                    <th className="px-3 py-2">Hours</th>
                                    <th className="px-3 py-2">Late</th>
                                    <th className="px-3 py-2">OT</th>
                                    <th className="px-3 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((r, idx) => (
                                    <tr key={`${r.staff_code}-${r.date}-${idx}`} className="hover:bg-slate-50">
                                        <td className="px-3 py-2 font-semibold text-slate-900">{r.full_name}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{r.staff_code}</td>
                                        <td className="px-3 py-2">{r.department}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{formatDate(r.date)}</td>
                                        <td className="px-3 py-2">{r.clock_in}</td>
                                        <td className="px-3 py-2">{r.clock_out}</td>
                                        <td className="px-3 py-2">{r.total_hours}</td>
                                        <td className="px-3 py-2">{r.late_minutes}</td>
                                        <td className="px-3 py-2">{r.overtime_minutes}</td>
                                        <td className="px-3 py-2">{r.notes}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && !loading && (
                                    <tr><td colSpan={10} className="px-3 py-6 text-center text-slate-500">No public holiday work records found.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Paginator meta={meta} onPage={load} />
                </div>
            )}

            {(editing === 'new' || editing) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={submit} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-bold text-slate-900">{editing === 'new' ? 'Add holiday' : 'Edit holiday'}</h2>
                        <div className="mt-4 space-y-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Date
                                <input type="date" name="date" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.date} onChange={change} required />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Name
                                <input type="text" name="name" className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.name} onChange={change} required />
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                Description
                                <textarea name="description" rows={3} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" value={form.description} onChange={change} />
                            </label>
                            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="checkbox" name="is_recurring" checked={form.is_recurring} onChange={change} />
                                Recurring every year
                            </label>
                        </div>
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={closeEdit} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">Cancel</button>
                            <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{saving ? 'Saving…' : 'Save'}</button>
                        </div>
                    </form>
                </div>
            )}
            <ConfirmDialog open={confirmState.open} title={confirmState.title} message={confirmState.message} onConfirm={confirmState.onConfirm} onCancel={() => setConfirmState({ ...confirmState, open: false })} />
        </div>
    );
}
