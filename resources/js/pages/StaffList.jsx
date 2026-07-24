import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useToast, ConfirmDialog } from '../components/Toast';

const SORT_OPTIONS = [
    { value: 'full_name', label: 'Name (A-Z)' },
    { value: 'full_name_desc', label: 'Name (Z-A)' },
    { value: 'staff_id', label: 'Staff ID' },
    { value: 'department', label: 'Department' },
    { value: 'created_at', label: 'Newest first' },
    { value: 'created_at_desc', label: 'Oldest first' },
];

export default function StaffList() {
    const { addToast, removeToast } = useToast();
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState('full_name');
    const [departments, setDepartments] = useState([]);
    const [departmentFilter, setDepartmentFilter] = useState('');
    const [confirmId, setConfirmId] = useState(null);

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
    }, []);

    const load = (page = 1) => {
        const params = { page, search, sort };
        if (departmentFilter) {
            params.department = departmentFilter;
        }
        api.get('/staff', { params }).then((r) => {
            setRows(r.data.data);
            setMeta({
                current_page: r.data.current_page,
                last_page: r.data.last_page,
            });
        });
    };

    useEffect(() => {
        load(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const deactivate = async (id) => {
        setConfirmId(id);
    };

    const confirmDeactivate = async () => {
        if (!confirmId) return;
        try {
            await api.delete(`/staff/${confirmId}`);
            addToast('Staff deactivated successfully', 'success');
            load(meta.current_page);
        } catch {
            addToast('Failed to deactivate staff', 'error');
        } finally {
            setConfirmId(null);
        }
    };

    const exportCsv = async () => {
        const res = await api.get('/staff/export', { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([res.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `HoganGuards_Staff_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    const importCsv = async () => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.csv';
        input.onchange = async (e) => {
            const file = e.target.files[0];
            if (! file) return;
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await api.post('/staff/import', fd);
                addToast(`Imported ${res.data.imported} staff. Skipped ${res.data.skipped}.`, 'success');
                load(meta.current_page);
            } catch (err) {
                addToast(err.response?.data?.message ?? 'Import failed', 'error');
            }
        };
        input.click();
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Staff directory</h1>
                    <p className="text-sm text-slate-500">Manage staff records and status.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={exportCsv} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                        Export CSV
                    </button>
                    <button type="button" onClick={importCsv} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                        Import CSV
                    </button>
                    <Link to="/staff/new" className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        Add staff
                    </Link>
                </div>
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-4">
                <label className="text-xs font-semibold text-slate-600 md:col-span-2">
                    Search
                    <input className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Search name, ID, department…" value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && load(1)} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Department
                    <select className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value={departmentFilter} onChange={(e) => setDepartmentFilter(e.target.value)}>
                        <option value="">All</option>
                        {departments.map((d) => (
                            <option key={d} value={d}>{d}</option>
                        ))}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Sort by
                    <select className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value={sort} onChange={(e) => setSort(e.target.value)}>
                        {SORT_OPTIONS.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="flex justify-end">
                <button type="button" onClick={() => load(1)} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Apply</button>
            </div>

            <ConfirmDialog
                open={Boolean(confirmId)}
                title="Deactivate staff"
                message="Are you sure you want to deactivate this staff member?"
                onConfirm={confirmDeactivate}
                onCancel={() => setConfirmId(null)}
            />

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Photo</th>
                            <th className="px-4 py-3">Staff ID</th>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Department</th>
                            <th className="px-4 py-3">Role</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((s) => (
                            <tr key={s.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2">
                                    <div className="h-10 w-10 overflow-hidden rounded-full bg-slate-200">
                                        {s.photo_url ? (
                                            <img src={s.photo_url} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="flex h-full items-center justify-center text-xs font-bold text-slate-500">
                                                {s.full_name?.slice(0, 1)}
                                            </div>
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-2 font-mono text-xs">{s.staff_id}</td>
                                <td className="px-4 py-2 font-semibold text-slate-900">{s.full_name}</td>
                                <td className="px-4 py-2">{s.department}</td>
                                <td className="px-4 py-2 text-slate-600">{s.job_title}</td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${s.employment_status === 'Active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}>
                                        {s.employment_status}
                                    </span>
                                </td>
                                <td className="px-4 py-2 text-right text-xs">
                                    <Link to={`/staff/${s.id}/edit`} className="ml-3 font-semibold text-sky-700 underline">Edit</Link>
                                    {s.employment_status === 'Active' && (
                                        <button type="button" className="ml-3 font-semibold text-red-600" onClick={() => deactivate(s.id)}>Deactivate</button>
                                    )}
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
        </div>
    );
}
