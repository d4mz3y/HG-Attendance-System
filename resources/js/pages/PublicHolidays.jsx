import React, { useEffect, useState } from 'react';
import api from '../api';

export default function PublicHolidays() {
    const [holidays, setHolidays] = useState([]);
    const [form, setForm] = useState({ date: '', name: '', description: '', is_recurring: false });
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [year, setYear] = useState(new Date().getFullYear());

    const load = () => {
        api.get('/public-holidays', { params: { year } }).then((r) => setHolidays(r.data));
    };

    useEffect(() => {
        load();
    }, [year]);

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
            load();
        } catch (err) {
            window.alert(err.response?.data?.message ?? 'Unable to save holiday');
        } finally {
            setSaving(false);
        }
    };

    const remove = async (id) => {
        if (!window.confirm('Remove this holiday?')) return;
        await api.delete(`/public-holidays/${id}`);
        load();
    };

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Public holidays</h1>
                    <p className="text-sm text-slate-500">Mark company-wide holidays. Attendance rules skip these days.</p>
                </div>
                <div className="flex gap-2">
                    <select className="rounded-lg border border-slate-300 px-3 py-2 text-sm" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                        {[2024, 2025, 2026, 2027, 2028].map((y) => <option key={y} value={y}>{y}</option>)}
                    </select>
                    <button type="button" onClick={openNew} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Add holiday</button>
                </div>
            </div>

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
                                <td className="px-3 py-2 whitespace-nowrap">{h.date}</td>
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
        </div>
    );
}
