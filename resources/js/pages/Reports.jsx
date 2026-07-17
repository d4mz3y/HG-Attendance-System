import React, { useEffect, useState } from 'react';
import api from '../api';
import { usePaginatedTable } from '../hooks/usePaginatedTable';
import Paginator from '../components/Paginator';

function iso(d) {
    return d.toISOString().slice(0, 10);
}

function presets(which) {
    const end = new Date();
    const start = new Date();
    if (which === 'today') {
        return { date_from: iso(start), date_to: iso(end) };
    }
    if (which === 'week') {
        const s = new Date();
        s.setDate(s.getDate() - 6);
        return { date_from: iso(s), date_to: iso(end) };
    }
    if (which === 'month') {
        const s = new Date(end.getFullYear(), end.getMonth(), 1);
        return { date_from: iso(s), date_to: iso(end) };
    }
    if (which === 'year') {
        const s = new Date(end.getFullYear(), 0, 1);
        return { date_from: iso(s), date_to: iso(end) };
    }
    return { date_from: iso(start), date_to: iso(end) };
}

export default function Reports() {
    const [departments, setDepartments] = useState([]);
    const [staffOpts, setStaffOpts] = useState([]);

    const { rows, meta, filters, load, updateFilter } = usePaginatedTable('/reports', {
        ...presets('month'),
        department: '',
        staff_pk: '',
        status: '',
    });

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
    }, []);

    const applyPreset = (key) => {
        updateFilter('date_from', presets(key).date_from);
        updateFilter('date_to', presets(key).date_to);
    };

    const exportXlsx = async () => {
        const body = {
            date_from: filters.date_from,
            date_to: filters.date_to,
            department: filters.department || undefined,
            staff_pk: filters.staff_pk ? Number(filters.staff_pk) : undefined,
            status: filters.status || undefined,
        };
        const res = await api.post('/reports/export', body, { responseType: 'blob' });
        const disposition = res.headers['content-disposition'];
        let filename = 'HoganGuards_Attendance.xlsx';
        if (disposition && disposition.includes('filename=')) {
            filename = disposition.split('filename=')[1].replaceAll('"', '').trim();
        }
        const url = window.URL.createObjectURL(new Blob([res.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    const exportPdf = async () => {
        const params = new URLSearchParams({
            date_from: filters.date_from,
            date_to: filters.date_to,
            department: filters.department,
            staff_pk: filters.staff_pk,
            status: filters.status,
        });
        window.open(`/api/reports/export/pdf?${params.toString()}`, '_blank');
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Reports</h1>
                    <p className="text-sm text-slate-500">Build attendance views and export.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={exportXlsx} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Export Excel</button>
                    <button type="button" onClick={exportPdf} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Export PDF</button>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                {[['Today', 'today'], ['Last 7 days', 'week'], ['This month', 'month'], ['This year', 'year']].map(([label, key]) => (
                    <button key={key} type="button" onClick={() => applyPreset(key)} className="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                        {label}
                    </button>
                ))}
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-2 lg:grid-cols-6">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input type="date" name="date_from" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input type="date" name="date_to" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Department
                    <select name="department" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.department} onChange={(e) => updateFilter('department', e.target.value)}>
                        <option value="">All</option>
                        {departments.map((d) => <option key={d} value={d}>{d}</option>)}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Staff
                    <select name="staff_pk" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.staff_pk} onChange={(e) => updateFilter('staff_pk', e.target.value)}>
                        <option value="">All</option>
                        {staffOpts.map((s) => <option key={s.id} value={s.id}>{s.full_name} ({s.staff_id})</option>)}
                    </select>
                </label>
                    <label className="text-xs font-semibold text-slate-600">
                        Status
                        <select name="status" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.status} onChange={(e) => updateFilter('status', e.target.value)}>
                            <option value="">All</option>
                            <option value="late">Late</option>
                            <option value="on_time">On time</option>
                            <option value="overtime">Overtime</option>
                            <option value="absent">Absent</option>
                            <option value="on_leave">On Leave</option>
                            <option value="incomplete">Incomplete</option>
                        </select>
                    </label>
                <div className="flex items-end">
                    <button type="button" onClick={() => load(1)} className="w-full rounded-lg bg-slate-900 py-2 text-sm font-semibold text-white">Run report</button>
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
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, idx) => (
                            <tr key={`${r.staff_code}-${r.date}-${idx}`} className="hover:bg-slate-50">
                                <td className="px-3 py-2 font-semibold text-slate-900">{r.full_name}</td>
                                <td className="px-3 py-2 font-mono text-xs">{r.staff_code}</td>
                                <td className="px-3 py-2">{r.department}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{r.date}</td>
                                <td className="px-3 py-2">{r.clock_in}</td>
                                <td className="px-3 py-2">{r.clock_out}</td>
                                <td className="px-3 py-2">{r.total_hours}</td>
                                <td className="px-3 py-2">{r.late_minutes}</td>
                                <td className="px-3 py-2">{r.overtime_minutes}</td>
                                <td className="px-3 py-2">{r.status}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Paginator meta={meta} onPage={load} />
        </div>
    );
}
