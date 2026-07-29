import React, { useEffect, useState } from 'react';
import api from '../api';

export default function Dashboard() {
    const [data, setData] = useState(null);
    const [modal, setModal] = useState(null);
    const [modalStaff, setModalStaff] = useState([]);
    const [modalLoading, setModalLoading] = useState(false);
    const [subscription, setSubscription] = useState(null);

    useEffect(() => {
        api.get('/dashboard/today').then((r) => setData(r.data));
        api.get('/subscription/status').then((r) => setSubscription(r.data)).catch(() => {});
    }, []);

    if (!data) {
        return <div className="text-slate-500">Loading dashboard…</div>;
    }

    const openCategory = (category) => {
        setModalLoading(true);
        setModal(category);
        api.get(`/dashboard/sessions/${category}`)
            .then((r) => setModalStaff(r.data))
            .finally(() => setModalLoading(false));
    };

    const closeModal = () => {
        setModal(null);
        setModalStaff([]);
    };

    return (
        <div className="space-y-8">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Today at HQ</h1>
                <p className="text-sm text-slate-500">{data.date}</p>
            </div>

            {subscription?.show_warning && !subscription?.active && (
                <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm animate-fade-in">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-sm font-semibold text-amber-900">Trial expiring soon</h2>
                            <p className="mt-1 text-sm text-amber-800">
                                Your free trial ends on{' '}
                                {new Date(subscription.trial_expiry).toLocaleDateString('en-US', {
                                    year: 'numeric', month: 'long', day: 'numeric',
                                })}.
                                After that, barcode scans will be limited to 20 per day and reports/audits will be locked.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setModal('upgrade')}
                            className="shrink-0 rounded-lg bg-hg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-hg-blue transition-colors"
                        >
                            Upgrade now
                        </button>
                    </div>
                </div>
            )}

            {data.alerts?.length > 0 && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <h2 className="text-sm font-semibold text-amber-800">Missed clock-outs</h2>
                    <ul className="mt-2 divide-y divide-amber-100">
                        {data.alerts.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <div>
                                    <div className="font-semibold text-slate-900">{a.staff_name}</div>
                                    <div className="text-xs text-slate-500">{a.department}</div>
                                </div>
                                <div className="text-xs text-amber-700">Open for {Math.floor(a.hours_open / 60)}h {a.hours_open % 60}m</div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {[
                    ['Open sessions', data.open_sessions, 'People currently clocked in', 'open'],
                    ['Completed sessions', data.completed_sessions, 'Finished shifts today', 'completed'],
                    ['Late clock-ins', data.late_clock_ins, 'Arrivals after shift start', 'late'],
                ].map(([title, value, hint, category]) => (
                    <button
                        type="button"
                        key={title}
                        onClick={() => openCategory(category)}
                        className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm text-left transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-slate-400 animate-fade-in"
                    >
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</div>
                        <div className="mt-2 text-3xl font-black text-slate-900">{value}</div>
                        <div className="mt-1 text-xs text-slate-500">{hint}</div>
                    </button>
                ))}
            </div>

            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={closeModal}>
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-bold text-slate-900">
                                {modal === 'open' ? 'Open Sessions' : modal === 'completed' ? 'Completed Sessions' : modal === 'late' ? 'Late Clock-ins' : modal === 'upgrade' ? 'Upgrade required' : 'Details'}
                            </h2>
                            <button type="button" onClick={closeModal} className="text-slate-400 hover:text-slate-600 text-xl leading-none" aria-label="Close">&times;</button>
                        </div>
                        {modal === 'upgrade' ? (
                            <div className="mt-4 space-y-4">
                                <p className="text-sm text-slate-600">
                                    This feature is locked because your free trial has ended. Upgrade to a paid plan to unlock reports and audit access.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {(subscription?.pricing || []).map((plan) => (
                                        <div key={plan.interval} className="rounded-xl border border-slate-200 bg-white p-4">
                                            <div className="text-sm font-semibold text-slate-900">{plan.description}</div>
                                            <div className="mt-2 text-2xl font-black text-slate-900">${(plan.price / 100).toFixed(2)}<span className="text-sm font-normal text-slate-500">/{plan.interval}</span></div>
                                            <button
                                                type="button"
                                                onClick={() => {/* Paystack integration would go here */}}
                                                className="mt-3 w-full rounded-lg bg-hg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-hg-blue transition-colors"
                                            >
                                                Subscribe
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : modalLoading ? (
                            <div className="mt-4 text-center text-sm text-slate-500">Loading…</div>
                        ) : modalStaff.length === 0 ? (
                            <div className="mt-4 text-center text-sm text-slate-500">No records found.</div>
                        ) : (
                            <div className="mt-4 max-h-80 overflow-y-auto">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="text-xs font-semibold uppercase text-slate-500">
                                        <tr>
                                            <th className="px-3 py-2">Staff</th>
                                            <th className="px-3 py-2">Dept</th>
                                            <th className="px-3 py-2">In</th>
                                            <th className="px-3 py-2">Out</th>
                                            <th className="px-3 py-2">Hours</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {modalStaff.map((s) => (
                                            <tr key={s.id} className="hover:bg-slate-50">
                                                <td className="px-3 py-2 font-medium text-slate-900">{s.full_name}</td>
                                                <td className="px-3 py-2 text-slate-500">{s.department}</td>
                                                <td className="px-3 py-2 whitespace-nowrap">{s.clock_in}</td>
                                                <td className="px-3 py-2 whitespace-nowrap">{s.clock_out ?? '—'}</td>
                                                <td className="px-3 py-2">{s.total_hours ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            )}

            <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-800">
                    Recent scans
                </div>
                <ul className="divide-y divide-slate-100">
                    {data.recent.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                            <div>
                                <div className="font-semibold text-slate-900">{row.staff?.full_name}</div>
                                <div className="text-xs text-slate-500">
                                    {row.staff?.department} · {row.staff?.staff_id}
                                </div>
                            </div>
                            <div className="text-right text-xs text-slate-500">
                                <div>{row.clock_in ? new Date(row.clock_in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</div>
                                <div>{row.clock_out ? 'Completed' : 'On site'}</div>
                            </div>
                        </li>
                    ))}
                    {data.recent.length === 0 && (
                        <li className="px-4 py-6 text-center text-sm text-slate-500">No scans recorded yet today.</li>
                    )}
                </ul>
            </div>
        </div>
    );
}
