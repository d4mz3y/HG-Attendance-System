import React, { useEffect, useState } from 'react';
import api from '../api';
import { usePaginatedTable } from '../hooks/usePaginatedTable';
import Paginator from '../components/Paginator';
import StaffPicker from '../components/StaffPicker';
import { localDateISO } from '../localDate';

function iso(d) {
    return localDateISO(d);
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
    const [companies, setCompanies] = useState([]);
    const [branches, setBranches] = useState([]);
    const [complianceMonth, setComplianceMonth] = useState(localDateISO().slice(0, 7));
    const [complianceRows, setComplianceRows] = useState([]);
    const [comparisonRows, setComparisonRows] = useState([]);
    const [analyticsLoading, setAnalyticsLoading] = useState('');
    const [analyticsError, setAnalyticsError] = useState('');

    const { rows, meta, filters, load, updateFilter } = usePaginatedTable('/reports', {
        ...presets('month'),
        department: '',
        company: '',
        branch: '',
        staff_pk: '',
        status: '',
    });

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/staff').then((r) => setStaffOpts(r.data));
        api.get('/lookups/companies').then((r) => setCompanies(Array.isArray(r.data) ? r.data : []));
        api.get('/lookups/branches').then((r) => setBranches(Array.isArray(r.data) ? r.data : []));
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
            company: filters.company || undefined,
            branch: filters.branch || undefined,
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
        const params = {
            date_from: filters.date_from,
            date_to: filters.date_to,
            department: filters.department || undefined,
            company: filters.company || undefined,
            branch: filters.branch || undefined,
            staff_pk: filters.staff_pk || undefined,
            status: filters.status || undefined,
        };
        const res = await api.get('/reports/export/pdf', { params, responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'HoganGuards_Attendance.pdf';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    };

    const loadCompliance = async () => {
        setAnalyticsLoading('compliance');
        setAnalyticsError('');
        try {
            const response = await api.get('/reports/compliance', { params: {
                month: complianceMonth,
                department: filters.department || undefined,
                company: filters.company || undefined,
                branch: filters.branch || undefined,
                staff_pk: filters.staff_pk || undefined,
            } });
            setComplianceRows(Array.isArray(response.data) ? response.data : [response.data]);
        } catch (requestError) {
            setComplianceRows([]);
            setAnalyticsError(requestError.response?.data?.message ?? 'Unable to calculate compliance.');
        } finally {
            setAnalyticsLoading('');
        }
    };

    const loadComparisons = async () => {
        setAnalyticsLoading('comparisons');
        setAnalyticsError('');
        try {
            const response = await api.get('/reports/comparisons', { params: {
                date_from: filters.date_from,
                date_to: filters.date_to,
            } });
            setComparisonRows(Array.isArray(response.data) ? response.data : []);
        } catch (requestError) {
            setComparisonRows([]);
            setAnalyticsError(requestError.response?.data?.message ?? 'Unable to compare departments.');
        } finally {
            setAnalyticsLoading('');
        }
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

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-2 lg:grid-cols-4">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input type="date" name="date_from" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input type="date" name="date_to" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Company
                    <select name="company" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.company} onChange={(e) => updateFilter('company', e.target.value)}>
                        <option value="">All</option>
                        {companies.map((company) => <option key={company} value={company}>{company}</option>)}
                    </select>
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    Branch
                    <select name="branch" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.branch} onChange={(e) => updateFilter('branch', e.target.value)}>
                        <option value="">All</option>
                        {branches.map((branch) => <option key={branch} value={branch}>{branch}</option>)}
                    </select>
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
                    <StaffPicker
                        name="staff_pk"
                        className="mt-1"
                        options={staffOpts}
                        value={filters.staff_pk}
                        onChange={(value) => updateFilter('staff_pk', value)}
                        emptyLabel="All staff"
                    />
                </label>
                    <label className="text-xs font-semibold text-slate-600">
                        Status
                        <select name="status" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.status} onChange={(e) => updateFilter('status', e.target.value)}>
                            <option value="">All</option>
                            <option value="late">Late</option>
                            <option value="on_time">On time</option>
                            <option value="overtime">Overtime</option>
                            <option value="absent">Absent</option>
                            <option value="day_off">Day Off</option>
                            <option value="on_leave">On Leave</option>
                            <option value="incomplete">Incomplete</option>
                            <option value="public_holiday_work">Public Holiday Work</option>
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
                                    <th className="px-3 py-2">Company</th>
                                    <th className="px-3 py-2">Branch</th>
                                    <th className="px-3 py-2">Dept</th>
                                    <th className="px-3 py-2">Session</th>
                                    <th className="px-3 py-2">Date</th>
                                    <th className="px-3 py-2">Holiday</th>
                                    <th className="px-3 py-2">In</th>
                                    <th className="px-3 py-2">Out</th>
                                    <th className="px-3 py-2">Hours</th>
                                    <th className="px-3 py-2">Late</th>
                                    <th className="px-3 py-2">OT</th>
                                    <th className="px-3 py-2">Notes</th>
                                    <th className="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((r, idx) => (
                                    <tr key={`${r.staff_code}-${r.date}-${idx}`} className="hover:bg-slate-50">
                                        <td className="px-3 py-2 font-semibold text-slate-900">{r.full_name}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{r.staff_code}</td>
                                        <td className="px-3 py-2">{r.company || '—'}</td>
                                        <td className="px-3 py-2">{r.branch || '—'}</td>
                                        <td className="px-3 py-2">{r.department}</td>
                                        <td className="px-3 py-2">{r.session_number || '—'}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{r.date}</td>
                                        <td className="px-3 py-2">{r.holiday_name || '—'}</td>
                                        <td className="px-3 py-2">{r.clock_in}</td>
                                        <td className="px-3 py-2">{r.clock_out}</td>
                                        <td className="px-3 py-2">{r.total_hours}</td>
                                        <td className="px-3 py-2">{r.late_minutes}</td>
                                        <td className="px-3 py-2">{r.overtime_minutes}</td>
                                        <td className="px-3 py-2">{r.notes}</td>
                                        <td className="px-3 py-2">{r.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                </table>
            </div>

            <Paginator meta={meta} onPage={load} />

            {analyticsError && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{analyticsError}</div>}

            <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900">Monthly compliance</h2>
                        <p className="text-sm text-slate-500">Attendance against completed required workdays, excluding approved leave.</p>
                    </div>
                    <div className="flex items-end gap-2">
                        <label className="text-xs font-semibold text-slate-600">Month<input type="month" className="mt-1 block rounded-lg border border-slate-300 px-2 py-1 text-sm" value={complianceMonth} onChange={(event) => setComplianceMonth(event.target.value)} /></label>
                        <button type="button" onClick={loadCompliance} disabled={analyticsLoading === 'compliance'} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{analyticsLoading === 'compliance' ? 'Calculating…' : 'Calculate'}</button>
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500"><tr><th className="px-3 py-2">Staff</th><th className="px-3 py-2">Department</th><th className="px-3 py-2">Working</th><th className="px-3 py-2">Leave</th><th className="px-3 py-2">Required</th><th className="px-3 py-2">Attended</th><th className="px-3 py-2">Score</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {complianceRows.map((row) => <tr key={row.staff_id}><td className="px-3 py-2"><div className="font-semibold">{row.staff_name}</div><div className="font-mono text-xs text-slate-500">{row.staff_code}</div></td><td className="px-3 py-2">{row.department}</td><td className="px-3 py-2">{row.working_days}</td><td className="px-3 py-2">{row.leave_days}</td><td className="px-3 py-2">{row.required_days}</td><td className="px-3 py-2">{row.attended_days}</td><td className="px-3 py-2 font-semibold">{row.score === null ? 'N/A' : `${row.score}%`}</td></tr>)}
                            {complianceRows.length === 0 && <tr><td colSpan={7} className="px-3 py-6 text-center text-slate-500">Choose filters above, then calculate a compliance month.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div><h2 className="text-lg font-bold text-slate-900">Department comparison</h2><p className="text-sm text-slate-500">Compare compliance, lateness, and overtime for the selected report dates.</p></div>
                    <button type="button" onClick={loadComparisons} disabled={analyticsLoading === 'comparisons'} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{analyticsLoading === 'comparisons' ? 'Comparing…' : 'Compare departments'}</button>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500"><tr><th className="px-3 py-2">Department</th><th className="px-3 py-2">Staff</th><th className="px-3 py-2">Average score</th><th className="px-3 py-2">Late sessions</th><th className="px-3 py-2">Overtime sessions</th></tr></thead><tbody className="divide-y divide-slate-100">{comparisonRows.map((row) => <tr key={row.department}><td className="px-3 py-2 font-semibold">{row.department}</td><td className="px-3 py-2">{row.staff_count}</td><td className="px-3 py-2">{row.avg_score === null ? 'N/A' : `${row.avg_score}%`}</td><td className="px-3 py-2">{row.late_count}</td><td className="px-3 py-2">{row.overtime_count}</td></tr>)}{comparisonRows.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-slate-500">Run a comparison for the selected dates.</td></tr>}</tbody></table>
                </div>
            </section>
        </div>
    );
}
