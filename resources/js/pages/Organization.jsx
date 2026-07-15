import React, { useEffect, useState } from 'react';
import api from '../api';

export default function Organization() {
    const [departments, setDepartments] = useState([]);
    const [branches, setBranches] = useState([]);
    const [newDept, setNewDept] = useState('');
    const [newBranch, setNewBranch] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');

    useEffect(() => {
        load();
    }, []);

    const load = () => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/branches').then((r) => setBranches(r.data));
    };

    const addDepartment = async (e) => {
        e.preventDefault();
        const trimmed = newDept.trim();
        if (!trimmed) return;
        if (departments.includes(trimmed)) {
            setMessage('Department already exists');
            return;
        }
        setSaving(true);
        setMessage('');
        try {
            const next = [...departments, trimmed];
            const res = await api.put('/lookups/departments', { departments: next });
            setDepartments(res.data.departments);
            setNewDept('');
            setMessage('Department added');
        } catch (err) {
            setMessage(err.response?.data?.message ?? 'Unable to save department');
        } finally {
            setSaving(false);
        }
    };

    const removeDepartment = async (name) => {
        if (!window.confirm(`Remove department "${name}"?`)) return;
        setSaving(true);
        try {
            const next = departments.filter((d) => d !== name);
            const res = await api.put('/lookups/departments', { departments: next });
            setDepartments(res.data.departments);
            setMessage('Department removed');
        } catch (err) {
            setMessage(err.response?.data?.message ?? 'Unable to remove department');
        } finally {
            setSaving(false);
        }
    };

    const addBranch = async (e) => {
        e.preventDefault();
        const trimmed = newBranch.trim();
        if (!trimmed) return;
        if (branches.includes(trimmed)) {
            setMessage('Branch already exists');
            return;
        }
        setSaving(true);
        setMessage('');
        try {
            const next = [...branches, trimmed];
            const res = await api.put('/lookups/branches', { branches: next });
            setBranches(res.data.branches);
            setNewBranch('');
            setMessage('Branch added');
        } catch (err) {
            setMessage(err.response?.data?.message ?? 'Unable to save branch');
        } finally {
            setSaving(false);
        }
    };

    const removeBranch = async (name) => {
        if (!window.confirm(`Remove branch "${name}"?`)) return;
        setSaving(true);
        try {
            const next = branches.filter((b) => b !== name);
            const res = await api.put('/lookups/branches', { branches: next });
            setBranches(res.data.branches);
            setMessage('Branch removed');
        } catch (err) {
            setMessage(err.response?.data?.message ?? 'Unable to remove branch');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Organization</h1>
                <p className="text-sm text-slate-500">Manage departments and branches used across the app.</p>
            </div>

            {message && (
                <div className="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">{message}</div>
            )}

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-900">Departments</h2>
                <p className="text-sm text-slate-500">These appear in the staff form, filters, and reports.</p>
                <ul className="mt-4 divide-y divide-slate-100">
                    {departments.map((d) => (
                        <li key={d} className="flex items-center justify-between py-2 text-sm">
                            <span className="font-medium text-slate-800">{d}</span>
                            <button type="button" onClick={() => removeDepartment(d)} className="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                        </li>
                    ))}
                </ul>
                <form onSubmit={addDepartment} className="mt-4 flex gap-2">
                    <input
                        className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="New department name"
                        value={newDept}
                        onChange={(e) => setNewDept(e.target.value)}
                    />
                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Add</button>
                </form>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-900">Branches</h2>
                <p className="text-sm text-slate-500">Branch locations. Lagos is the HQ.</p>
                <ul className="mt-4 divide-y divide-slate-100">
                    {branches.map((b) => (
                        <li key={b} className="flex items-center justify-between py-2 text-sm">
                            <span className="font-medium text-slate-800">{b}</span>
                            <button type="button" onClick={() => removeBranch(b)} className="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                        </li>
                    ))}
                </ul>
                <form onSubmit={addBranch} className="mt-4 flex gap-2">
                    <input
                        className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="New branch name"
                        value={newBranch}
                        onChange={(e) => setNewBranch(e.target.value)}
                    />
                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Add branch</button>
                </form>
            </div>
        </div>
    );
}
