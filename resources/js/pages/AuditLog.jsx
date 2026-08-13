import React, { useEffect, useState } from 'react';
import api from '../api';
import { localDateISO } from '../localDate';
import { useAuth } from '../AuthContext';
import { useToast } from '../components/Toast';
import { formatDateTime } from '../timeFormat';

export default function AuditLog() {
    const { user, can } = useAuth();
    const { addToast } = useToast();
    const [tab, setTab] = useState('attendance');
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [filters, setFilters] = useState({
        date_from: localDateISO(new Date(new Date().getFullYear(), new Date().getMonth(), 1)),
        date_to: localDateISO(),
    });
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteReason, setDeleteReason] = useState('');
    const [deleting, setDeleting] = useState(false);
    const canDelete = user?.role === 'super_admin';
    const canViewAdministrativeActivity = can('audit.activity.view');
    const auditColumnCount = (tab === 'attendance' ? 4 : 5) + (canViewAdministrativeActivity ? 1 : 0) + (canDelete ? 1 : 0);

    const load = (page = 1) => {
        const params = { page, date_from: filters.date_from, date_to: filters.date_to };
        setLoading(true);
        setError('');
        api.get(tab === 'attendance' ? '/audits' : '/activity-logs', { params })
            .then((r) => {
                setRows(r.data.data);
                setMeta({ current_page: r.data.current_page, last_page: r.data.last_page });
            })
            .catch((requestError) => {
                setRows([]);
                setError(requestError.response?.data?.message ?? 'Unable to load audit records.');
            })
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (tab === 'activity' && !canViewAdministrativeActivity) {
            setTab('attendance');
            return;
        }

        load(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, canViewAdministrativeActivity]);

    const change = (e) => {
        const { name, value } = e.target;
        setFilters((f) => ({ ...f, [name]: value }));
    };

    const openDeleteDialog = (row) => {
        setDeleteTarget({ row, type: tab });
        setDeleteReason('');
    };

    const deleteRecord = async (event) => {
        event.preventDefault();
        if (!deleteTarget || deleteReason.trim().length < 3) return;

        setDeleting(true);
        try {
            const endpoint = deleteTarget.type === 'attendance'
                ? `/audits/${deleteTarget.row.id}`
                : `/activity-logs/${deleteTarget.row.id}`;
            await api.delete(endpoint, { data: { reason: deleteReason.trim() } });
            if (tab === deleteTarget.type) {
                setRows((current) => current.filter((row) => row.id !== deleteTarget.row.id));
            }
            setDeleteTarget(null);
            setDeleteReason('');
            addToast('Audit record permanently deleted.', 'success');
        } catch (requestError) {
            addToast(requestError.response?.data?.message ?? 'Unable to delete that audit record.', 'error');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Audit log</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                    {canViewAdministrativeActivity
                        ? 'Track attendance corrections and administrative changes across the system.'
                        : 'Track attendance corrections, with clear reasons for each change.'}
                </p>
            </div>

            <div className="flex gap-2">
                <button type="button" onClick={() => setTab('attendance')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'attendance' ? 'bg-slate-900 text-white dark:bg-sky-700' : 'border border-slate-300 bg-white text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'}`}>Attendance changes</button>
                {canViewAdministrativeActivity && <button type="button" onClick={() => setTab('activity')} className={`rounded-lg px-4 py-2 text-sm font-semibold ${tab === 'activity' ? 'bg-slate-900 text-white dark:bg-sky-700' : 'border border-slate-300 bg-white text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'}`}>Administrative activity</button>}
            </div>

            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-3 dark:border-slate-700 dark:bg-slate-900">
                <label className="text-xs font-semibold text-slate-600 dark:text-slate-300">
                    From
                    <input type="date" name="date_from" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" value={filters.date_from} onChange={change} />
                </label>
                <label className="text-xs font-semibold text-slate-600 dark:text-slate-300">
                    To
                    <input type="date" name="date_to" className="mt-1 w-full rounded-lg border border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" value={filters.date_to} onChange={change} />
                </label>
                <div className="flex items-end">
                    <button type="button" onClick={() => load(1)} className="w-full rounded-lg bg-slate-900 py-2 text-sm font-semibold text-white">Apply</button>
                </div>
            </div>

            {error && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/60 dark:text-red-200">{error}</div>}

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-800/70 dark:text-slate-300">
                        <tr>
                            <th className="px-3 py-2">When</th>
                            <th className="px-3 py-2">{tab === 'attendance' ? 'Staff member' : 'Action'}</th>
                            {tab === 'activity' && <th className="px-3 py-2">Request</th>}
                            <th className="px-3 py-2">Changed by</th>
                            {canViewAdministrativeActivity && <th className="px-3 py-2">IP</th>}
                            <th className="px-3 py-2">{tab === 'attendance' ? 'Why' : 'Outcome'}</th>
                            {canDelete && <th className="px-3 py-2 text-right">Super admin</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                        {rows.map((r) => (
                            <tr key={r.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <td className="px-3 py-2 whitespace-nowrap text-slate-700 dark:text-slate-200">{formatDateTime(r.created_at)}</td>
                                {tab === 'attendance' ? (
                                    <>
                                        <td className="px-3 py-2">
                                            <div className="font-semibold text-slate-900 dark:text-slate-100">{r.attendance?.staff?.full_name}</div>
                                            <div className="font-mono text-xs text-slate-500 dark:text-slate-400">{r.attendance?.staff?.staff_id}</div>
                                        </td>
                                        <td className="px-3 py-2 text-slate-700 dark:text-slate-200">{r.changed_by || '—'}</td>
                                    </>
                                ) : (
                                    <>
                                        <td className="px-3 py-2"><div className="font-semibold text-slate-900 dark:text-slate-100">{r.action}</div><div className="font-mono text-xs text-slate-500 dark:text-slate-400">{r.subject_type?.split('\\').pop()}{r.subject_id ? ` #${r.subject_id}` : ''}</div></td>
                                        <td className="px-3 py-2"><div className="font-mono text-xs text-slate-700 dark:text-slate-200">{r.method} {r.path}</div><div className="max-w-sm truncate text-xs text-slate-500 dark:text-slate-400">{JSON.stringify(r.payload ?? {})}</div></td>
                                        <td className="px-3 py-2 text-slate-700 dark:text-slate-200">{r.user?.username || '—'}</td>
                                    </>
                                )}
                                {canViewAdministrativeActivity && <td className="px-3 py-2 font-mono text-xs text-slate-700 dark:text-slate-200">{r.ip_address || '—'}</td>}
                                <td className="px-3 py-2 max-w-xs truncate text-slate-700 dark:text-slate-200">{tab === 'attendance' ? (r.reason || '—') : <span className={r.status_code < 400 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}>HTTP {r.status_code}</span>}</td>
                                {canDelete && <td className="px-3 py-2 text-right"><button type="button" onClick={() => openDeleteDialog(r)} className="rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50 hover:text-red-800 dark:text-red-300 dark:hover:bg-red-950/60">Delete permanently</button></td>}
                            </tr>
                        ))}
                        {!loading && rows.length === 0 && <tr><td colSpan={auditColumnCount} className="px-3 py-8 text-center text-slate-500 dark:text-slate-400">No audit records found.</td></tr>}
                        {loading && <tr><td colSpan={auditColumnCount} className="px-3 py-8 text-center text-slate-500 dark:text-slate-400">Loading…</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="flex items-center justify-between text-sm text-slate-600 dark:text-slate-300">
                <span>Page {meta.current_page} of {meta.last_page}</span>
                <div className="flex gap-2">
                    <button type="button" disabled={meta.current_page <= 1} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-40 dark:border-slate-600 dark:text-slate-200" onClick={() => load(meta.current_page - 1)}>Previous</button>
                    <button type="button" disabled={meta.current_page >= meta.last_page} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-40 dark:border-slate-600 dark:text-slate-200" onClick={() => load(meta.current_page + 1)}>Next</button>
                </div>
            </div>

            {deleteTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="delete-audit-title">
                    <form onSubmit={deleteRecord} className="w-full max-w-lg rounded-2xl border border-red-200 bg-white p-6 shadow-xl dark:border-red-900 dark:bg-slate-900">
                        <h2 id="delete-audit-title" className="text-lg font-bold text-slate-900 dark:text-slate-100">Permanently delete this audit record?</h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">This cannot be undone. Give a short reason so the deletion itself is recorded in the administrative activity log.</p>
                        <label className="mt-4 block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Deletion reason
                            <textarea autoFocus required minLength={3} maxLength={500} value={deleteReason} onChange={(event) => setDeleteReason(event.target.value)} className="mt-1 min-h-24 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" placeholder="Why is this record being removed?" />
                        </label>
                        <div className="mt-5 flex justify-end gap-3">
                            <button type="button" disabled={deleting} onClick={() => setDeleteTarget(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-600 dark:text-slate-200">Cancel</button>
                            <button disabled={deleting || deleteReason.trim().length < 3} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">{deleting ? 'Deleting…' : 'Delete permanently'}</button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
