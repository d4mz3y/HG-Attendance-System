import React, { useEffect, useState } from 'react';
import api from '../api';

export default function Dashboard() {
    const [data, setData] = useState(null);

    useEffect(() => {
        api.get('/dashboard/today').then((r) => setData(r.data));
    }, []);

    if (!data) {
        return <div className="text-slate-500">Loading dashboard…</div>;
    }

    return (
        <div className="space-y-8">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">Today at HQ</h1>
                <p className="text-sm text-slate-500">{data.date}</p>
            </div>

            {data.alerts?.length > 0 && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <h2 className="text-sm font-semibold text-amber-800">Missed clock-outs</h2>
                    <ul className="mt-2 divide-y divide-amber-100">
                        {data.alerts.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <div>
                                    <div className="font-semibold text-slate-900">{a.staff_name}</div>
                                    <div className="text-xs text-slate-500">{a.department} · {a.staff_code}</div>
                                </div>
                                <div className="text-xs text-amber-700">Open for {Math.floor(a.hours_open / 60)}h {a.hours_open % 60}m</div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {[
                    ['Open sessions', data.open_sessions, 'People currently clocked in'],
                    ['Completed sessions', data.completed_sessions, 'Finished shifts today'],
                    ['Late clock-ins', data.late_clock_ins, 'Arrivals after shift start'],
                ].map(([title, value, hint]) => (
                    <div key={title} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</div>
                        <div className="mt-2 text-3xl font-black text-slate-900">{value}</div>
                        <div className="mt-1 text-xs text-slate-500">{hint}</div>
                    </div>
                ))}
            </div>

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
                                <div>{new Date(row.clock_in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
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
