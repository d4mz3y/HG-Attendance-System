import React, { useEffect, useState } from 'react';
import api from '../api';

export default function AuditLog() {
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [filters, setFilters] = useState({
        date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        date_to: new Date().toISOString().slice(0, 10),
    });

    const load = (page = 1) => {
        const params = { page, date_from: filters.date_from, date_to: filters.date_to };
        api.get('/audits', { params }).then((r) => {
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

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Audit log</h1>
                <p className="text-sm text-slate-500">Track changes made to attendance records.</p>
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3">
                <label className="text-xs font-semibold text-slate-600">
                    From
                    <input type="date" name="date_from" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_from} onChange={change} />
                </label>
                <label className="text-xs font-semibold text-slate-600">
                    To
                    <input type="date" name="date_to" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm" value={filters.date_to} onChange={change} />
                </label>
                <div className="flex items-end">
                    <button type="button" onClick={() => load(1)} className="w-full rounded-lg bg-slate-900 py-2 text-sm font-semibold text-white">Apply</button>
                </div>
            </div>

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Date</th>
                            <th className="px-3 py-2">Staff</th>
                            <th className="px-3 py-2">Changed fields</th>
                            <th className="px-3 py-2">Changed by</th>
                            <th className="px-3 py-2">IP</th>
                            <th className="px-3 py-2">Reason</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50">
                                <td className="px-3 py-2 whitespace-nowrap">{new Date(r.created_at).toLocaleString()}</td>
                                <td className="px-3 py-2">
                                    <div className="font-semibold text-slate-900">{r.attendance?.staff?.full_name}</div>
                                    <div className="font-mono text-xs text-slate-500">{r.attendance?.staff?.staff_id}</div>
                                </td>
                                <td className="px-3 py-2 font-mono text-xs">{r.changed_fields?.join(', ')}</td>
                                <td className="px-3 py-2">{r.changed_by || '—'}</td>
                                <td className="px-3 py-2 font-mono text-xs">{r.ip_address || '—'}</td>
                                <td className="px-3 py-2 max-w-xs truncate">{r.reason || '—'}</td>
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
