import React, { useCallback, useEffect, useState } from 'react';
import api from '../api';
import { useToast, ConfirmDialog } from '../components/Toast';
import { useAuth } from '../AuthContext';

function errorMessage(error, fallback) {
    const errors = error.response?.data?.errors;
    const validationMessage = errors && typeof errors === 'object'
        ? Object.values(errors).flat().find(Boolean)
        : null;

    return validationMessage || error.response?.data?.message || fallback;
}

export default function Organization() {
    const [departments, setDepartments] = useState([]);
    const [branches, setBranches] = useState([]);
    const [companies, setCompanies] = useState([]);
    const [newDept, setNewDept] = useState('');
    const [newBranch, setNewBranch] = useState('');
    const [newCompany, setNewCompany] = useState('');
    const [saving, setSaving] = useState(false);
    const { addToast } = useToast();
    const { can } = useAuth();
    const [confirmState, setConfirmState] = useState({ open: false, title: '', message: '', onConfirm: () => {} });
    const canManage = can('organization.manage');

    const hasDepartment = (name) => departments.some((department) =>
        department.trim().toLocaleLowerCase() === name.trim().toLocaleLowerCase()
    );

    const load = useCallback(async () => {
        try {
            const [departmentResponse, branchResponse, companyResponse] = await Promise.all([
                api.get('/lookups/departments'),
                api.get('/lookups/branches'),
                api.get('/lookups/companies'),
            ]);
            setDepartments(departmentResponse.data);
            setBranches(branchResponse.data);
            setCompanies(companyResponse.data);
        } catch (error) {
            addToast(errorMessage(error, 'Unable to load organization settings.'), 'error');
        }
    }, [addToast]);

    useEffect(() => {
        void load();
    }, [load]);

    const addDepartment = async (e) => {
        e.preventDefault();
        const trimmed = newDept.trim();
        if (!trimmed) return;
        if (hasDepartment(trimmed)) {
            addToast('A department with that name already exists.', 'warning');
            return;
        }
        setSaving(true);
        try {
            const next = [...departments, trimmed];
            const res = await api.put('/lookups/departments', { departments: next });
            setDepartments(res.data.departments);
            setNewDept('');
            addToast('Department added.', 'success');
        } catch (err) {
            addToast(errorMessage(err, 'Unable to add department.'), 'error');
        } finally {
            setSaving(false);
        }
    };

    const removeDepartment = async (name) => {
        if (saving) return;

        setConfirmState({ open: true, title: 'Remove department', message: `Remove department "${name}"? Departments assigned to staff must be reassigned before they can be removed.`, onConfirm: async () => {
            setSaving(true);
            try {
                const next = departments.filter((d) => d !== name);
                const res = await api.put('/lookups/departments', { departments: next });
                setDepartments(res.data.departments);
                addToast('Department removed.', 'success');
            } catch (err) {
                addToast(errorMessage(err, 'Unable to remove department.'), 'error');
            } finally {
                setSaving(false);
            }
        }});
    };

    const addBranch = async (e) => {
        e.preventDefault();
        const trimmed = newBranch.trim();
        if (!trimmed) return;
        if (branches.includes(trimmed)) {
            addToast('Branch already exists.', 'warning');
            return;
        }
        setSaving(true);
        try {
            const next = [...branches, trimmed];
            const res = await api.put('/lookups/branches', { branches: next });
            setBranches(res.data.branches);
            setNewBranch('');
            addToast('Branch added.', 'success');
        } catch (err) {
            addToast(errorMessage(err, 'Unable to save branch.'), 'error');
        } finally {
            setSaving(false);
        }
    };

    const removeBranch = async (name) => {
        setConfirmState({ open: true, title: 'Confirm', message: `Remove branch "${name}"?`, onConfirm: async () => {
            setSaving(true);
            try {
                const next = branches.filter((b) => b !== name);
                const res = await api.put('/lookups/branches', { branches: next });
                setBranches(res.data.branches);
                addToast('Branch removed.', 'success');
            } catch (err) {
                addToast(errorMessage(err, 'Unable to remove branch.'), 'error');
            } finally {
                setSaving(false);
            }
        }});
    };

    const addCompany = async (e) => {
        e.preventDefault();
        const trimmed = newCompany.trim();
        if (!trimmed) return;
        if (companies.includes(trimmed)) {
            addToast('Company already exists.', 'warning');
            return;
        }
        setSaving(true);
        try {
            const next = [...companies, trimmed];
            const res = await api.put('/lookups/companies', { companies: next });
            setCompanies(res.data.companies);
            setNewCompany('');
            addToast('Company added.', 'success');
        } catch (err) {
            addToast(errorMessage(err, 'Unable to save company.'), 'error');
        } finally {
            setSaving(false);
        }
    };

    const removeCompany = async (name) => {
        setConfirmState({ open: true, title: 'Confirm', message: `Remove company "${name}"?`, onConfirm: async () => {
            setSaving(true);
            try {
                const next = companies.filter((c) => c !== name);
                const res = await api.put('/lookups/companies', { companies: next });
                setCompanies(res.data.companies);
                addToast('Company removed.', 'success');
            } catch (err) {
                addToast(errorMessage(err, 'Unable to remove company.'), 'error');
            } finally {
                setSaving(false);
            }
        }});
    };

    return (
        <div className="mx-auto max-w-3xl space-y-8">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Organization</h1>
                <p className="text-sm text-slate-500">Manage departments and branches used across the app.</p>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-900">Companies</h2>
                <p className="text-sm text-slate-500">Sibling companies that share this attendance system.</p>
                <ul className="mt-4 divide-y divide-slate-100">
                    {companies.map((c) => (
                        <li key={c} className="flex items-center justify-between py-2 text-sm">
                            <span className="font-medium text-slate-800">{c}</span>
                            {canManage && <button type="button" onClick={() => removeCompany(c)} className="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>}
                        </li>
                    ))}
                </ul>
                {canManage && <form onSubmit={addCompany} className="mt-4 flex gap-2">
                    <input
                        id="new_company"
                        name="new_company"
                        className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="New company name"
                        value={newCompany}
                        onChange={(e) => setNewCompany(e.target.value)}
                    />
                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Add company</button>
                </form>}
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-900">Departments</h2>
                <p className="text-sm text-slate-500">These appear in the staff form, filters, and reports.</p>
                <ul className="mt-4 divide-y divide-slate-100">
                    {departments.map((d) => (
                        <li key={d} className="flex items-center justify-between py-2 text-sm">
                            <span className="font-medium text-slate-800">{d}</span>
                            {canManage && <button type="button" disabled={saving} onClick={() => removeDepartment(d)} className="text-xs font-semibold text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-50">Remove</button>}
                        </li>
                    ))}
                </ul>
                {canManage && <form onSubmit={addDepartment} className="mt-4 flex gap-2">
                    <input
                        id="new_department"
                        name="new_department"
                        className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="New department name"
                        value={newDept}
                        onChange={(e) => setNewDept(e.target.value)}
                    />
                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Add</button>
                </form>}
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 className="text-lg font-bold text-slate-900">Branches</h2>
                <p className="text-sm text-slate-500">Branch locations. Lagos is the HQ.</p>
                <ul className="mt-4 divide-y divide-slate-100">
                    {branches.map((b) => (
                        <li key={b} className="flex items-center justify-between py-2 text-sm">
                            <span className="font-medium text-slate-800">{b}</span>
                            {canManage && <button type="button" onClick={() => removeBranch(b)} className="text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>}
                        </li>
                    ))}
                </ul>
                {canManage && <form onSubmit={addBranch} className="mt-4 flex gap-2">
                    <input
                        id="new_branch"
                        name="new_branch"
                        className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        placeholder="New branch name"
                        value={newBranch}
                        onChange={(e) => setNewBranch(e.target.value)}
                    />
                    <button type="submit" disabled={saving} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Add branch</button>
                </form>}
            </div>
            <ConfirmDialog open={confirmState.open} title={confirmState.title} message={confirmState.message} onConfirm={confirmState.onConfirm} onCancel={() => setConfirmState({ ...confirmState, open: false })} />
        </div>
    );
}
