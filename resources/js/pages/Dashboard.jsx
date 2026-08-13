import React, { useEffect, useState } from 'react';
import api from '../api';
import { useToast } from '../components/Toast';

const ALERT_PAGE_SIZE = 5;

const formatTime = (value) => value
    ? new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date(value))
    : '—';

const ALERT_TONES = {
    amber: {
        panel: 'border-amber-200 bg-white dark:border-amber-900/70 dark:bg-slate-900',
        icon: 'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-300',
        card: 'border-amber-100 bg-amber-50/60 dark:border-amber-900/50 dark:bg-amber-950/20',
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200',
        button: 'border-amber-200 text-amber-800 hover:bg-amber-50 dark:border-amber-900/70 dark:text-amber-200 dark:hover:bg-amber-950/40',
    },
    red: {
        panel: 'border-red-200 bg-white dark:border-red-900/70 dark:bg-slate-900',
        icon: 'bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-300',
        card: 'border-red-100 bg-red-50/60 dark:border-red-900/50 dark:bg-red-950/20',
        badge: 'bg-red-100 text-red-800 dark:bg-red-400/15 dark:text-red-200',
        button: 'border-red-200 text-red-800 hover:bg-red-50 dark:border-red-900/70 dark:text-red-200 dark:hover:bg-red-950/40',
    },
};

function AlertRail({ title, description, icon, items, page, setPage, tone, children }) {
    const palette = ALERT_TONES[tone];
    const pageCount = Math.max(1, Math.ceil(items.length / ALERT_PAGE_SIZE));
    const activePage = Math.min(page, pageCount - 1);
    const visibleItems = items.slice(activePage * ALERT_PAGE_SIZE, (activePage + 1) * ALERT_PAGE_SIZE);
    const start = activePage * ALERT_PAGE_SIZE + 1;
    const end = Math.min(items.length, start + ALERT_PAGE_SIZE - 1);

    return (
        <section className={`rounded-2xl border p-4 shadow-sm ${palette.panel}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-base ${palette.icon}`} aria-hidden="true">{icon}</div>
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">{title}</h2>
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{description}</p>
                    </div>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${palette.badge}`}>{items.length}</span>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                {visibleItems.map(children)}
            </div>

            {pageCount > 1 && (
                <div className="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <span className="text-xs text-slate-500 dark:text-slate-400">Showing {start}–{end} of {items.length}</span>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setPage((current) => Math.max(0, current - 1))} disabled={activePage === 0} className={`rounded-lg border px-2.5 py-1 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40 ${palette.button}`} aria-label={`Show previous ${title.toLowerCase()}`}>Previous</button>
                        <button type="button" onClick={() => setPage((current) => Math.min(pageCount - 1, current + 1))} disabled={activePage === pageCount - 1} className={`rounded-lg border px-2.5 py-1 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-40 ${palette.button}`} aria-label={`Show more ${title.toLowerCase()}`}>Next</button>
                    </div>
                </div>
            )}
        </section>
    );
}

export default function Dashboard() {
    const [data, setData] = useState(null);
    const [modal, setModal] = useState(null);
    const [modalStaff, setModalStaff] = useState([]);
    const [modalLoading, setModalLoading] = useState(false);
    const [subscription, setSubscription] = useState(null);
    const [billingEmail, setBillingEmail] = useState('');
    const [paymentLoading, setPaymentLoading] = useState(null);
    const [missedClockOutPage, setMissedClockOutPage] = useState(0);
    const [absencePage, setAbsencePage] = useState(0);
    const [lateClockInPage, setLateClockInPage] = useState(0);
    const { addToast } = useToast();

    useEffect(() => {
        api.get('/dashboard/today').then((r) => setData(r.data));
        api.get('/subscription/status').then((r) => {
            setSubscription(r.data);
            setBillingEmail(r.data.billing_email || '');
        }).catch(() => {});
        const payment = new URLSearchParams(window.location.search).get('payment');
        if (payment === 'success') addToast('Payment confirmed. Your licence is active.', 'success');
        if (payment === 'failed') addToast('Payment could not be verified. No charge was applied to the licence.', 'error');
    }, [addToast]);

    const subscribe = async (plan) => {
        if (!billingEmail) {
            addToast('Enter the billing email first.', 'error');
            return;
        }
        setPaymentLoading(plan.plan);
        try {
            const { data: payment } = await api.post('/subscription/initialize', { plan: plan.plan, email: billingEmail });
            window.location.assign(payment.authorization_url);
        } catch (error) {
            addToast(error.response?.data?.message || 'Unable to start Paystack checkout.', 'error');
            setPaymentLoading(null);
        }
    };

    const money = (plan) => new Intl.NumberFormat(undefined, { style: 'currency', currency: plan.currency }).format(plan.amount / 100);

    if (!data) {
        return <div className="text-slate-500 dark:text-slate-400">Loading dashboard…</div>;
    }

    const missedClockOuts = Array.isArray(data.alerts) ? data.alerts : [];
    const expectedStaff = Array.isArray(data.absence_alerts) ? data.absence_alerts : [];
    const lateClockIns = Array.isArray(data.late_clock_in_alerts) ? data.late_clock_in_alerts : [];
    const recentScans = Array.isArray(data.recent) ? data.recent : [];

    const openCategory = (category) => {
        setModalLoading(true);
        setModal(category);
        api.get(`/dashboard/sessions/${category}`)
            .then((r) => setModalStaff(Array.isArray(r.data) ? r.data : []))
            .finally(() => setModalLoading(false));
    };

    const closeModal = () => {
        setModal(null);
        setModalStaff([]);
    };

    return (
        <div className="space-y-8">
            <div>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Today at HQ</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">{data.date}</p>
            </div>

            {subscription?.show_warning && (
                <div className="rounded-2xl border border-amber-200 bg-white p-4 shadow-sm animate-fade-in dark:border-amber-900/70 dark:bg-slate-900">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">{subscription.paid ? 'Licence renewal due soon' : subscription.active ? 'Trial expiring soon' : 'Licence required'}</h2>
                            <p className="mt-1 text-sm text-amber-800 dark:text-amber-100">
                                {subscription.paid ? 'Your licence expires' : subscription.active ? 'Your free trial ends' : 'Your free trial ended'} on{' '}
                                {new Date(subscription.paid ? subscription.subscription_expiry : subscription.trial_expiry).toLocaleDateString('en-US', {
                                    year: 'numeric', month: 'long', day: 'numeric',
                                })}.
                                {!subscription.paid && ' After that, barcode scans are limited and reports/audits are locked.'}
                            </p>
                        </div>
                        {subscription.can_manage_billing && <button
                            type="button"
                            onClick={() => setModal('upgrade')}
                            className="shrink-0 rounded-lg bg-hg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-hg-blue transition-colors"
                        >
                            Upgrade now
                        </button>}
                    </div>
                </div>
            )}

            {missedClockOuts.length > 0 && (
                <AlertRail
                    title="Missed clock-outs"
                    description="Open sessions needing attention"
                    icon="↗"
                    items={missedClockOuts}
                    page={missedClockOutPage}
                    setPage={setMissedClockOutPage}
                    tone="amber"
                >
                    {(alert) => (
                        <article key={alert.id} className="min-w-0 rounded-xl border border-amber-100 bg-amber-50/60 px-3 py-2.5 dark:border-amber-900/50 dark:bg-amber-950/20">
                            <div className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100" title={alert.staff_name}>{alert.staff_name}</div>
                            <div className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400" title={alert.department}>{alert.department || 'No department'}</div>
                            <div className="mt-2 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">Open {Math.floor(alert.minutes_open / 60)}h {alert.minutes_open % 60}m</div>
                        </article>
                    )}
                </AlertRail>
            )}

            {expectedStaff.length > 0 && (
                <AlertRail
                    title="Expected staff not clocked in"
                    description="Scheduled today, with no scan yet"
                    icon="!"
                    items={expectedStaff}
                    page={absencePage}
                    setPage={setAbsencePage}
                    tone="red"
                >
                    {(alert) => (
                        <article key={alert.id} className="min-w-0 rounded-xl border border-red-100 bg-red-50/60 px-3 py-2.5 dark:border-red-900/50 dark:bg-red-950/20">
                            <div className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100" title={alert.staff_name}>{alert.staff_name}</div>
                            <div className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400" title={alert.department}>{alert.department || 'No department'}</div>
                            <div className="mt-2 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800 dark:bg-red-400/15 dark:text-red-200">No scan yet</div>
                        </article>
                    )}
                </AlertRail>
            )}

            {lateClockIns.length > 0 && (
                <AlertRail
                    title="Late clock-ins"
                    description="Arrivals after their shift's grace period"
                    icon="◷"
                    items={lateClockIns}
                    page={lateClockInPage}
                    setPage={setLateClockInPage}
                    tone="amber"
                >
                    {(alert) => (
                        <article key={alert.id} className="min-w-0 rounded-xl border border-amber-100 bg-amber-50/60 px-3 py-2.5 dark:border-amber-900/50 dark:bg-amber-950/20">
                            <div className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100" title={alert.staff_name}>{alert.staff_name}</div>
                            <div className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400" title={alert.department}>{alert.department || 'No department'}</div>
                            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">{formatTime(alert.clock_in)}</span>
                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">{alert.late_minutes} min late</span>
                            </div>
                        </article>
                    )}
                </AlertRail>
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
                        className="rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-slate-400 animate-fade-in dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
                    >
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{title}</div>
                        <div className="mt-2 text-3xl font-black text-slate-900 dark:text-slate-100">{value}</div>
                        <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">{hint}</div>
                    </button>
                ))}
            </div>

            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={closeModal}>
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                {modal === 'open' ? 'Open Sessions' : modal === 'completed' ? 'Completed Sessions' : modal === 'late' ? 'Late Clock-ins' : modal === 'upgrade' ? 'Upgrade required' : 'Details'}
                            </h2>
                            <button type="button" onClick={closeModal} className="text-xl leading-none text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Close">&times;</button>
                        </div>
                        {modal === 'upgrade' ? (
                            <div className="mt-4 space-y-4">
                                <p className="text-sm text-slate-600 dark:text-slate-300">
                                    Pay securely through Paystack to activate or extend the attendance licence. The server verifies the reference, amount, currency, and plan before activation.
                                </p>
                                <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">Billing email<input type="email" value={billingEmail} onChange={(event) => setBillingEmail(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" required /></label>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {(Array.isArray(subscription?.pricing) ? subscription.pricing : []).map((plan) => (
                                        <div key={plan.interval} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                                            <div className="text-sm font-semibold text-slate-900 dark:text-slate-100">{plan.label}</div>
                                            <div className="mt-2 text-2xl font-black text-slate-900 dark:text-slate-100">{money(plan)}<span className="text-sm font-normal text-slate-500 dark:text-slate-400">/{plan.interval}</span></div>
                                            <button
                                                type="button"
                                                onClick={() => subscribe(plan)}
                                                disabled={paymentLoading !== null}
                                                className="mt-3 w-full rounded-lg bg-hg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-hg-blue transition-colors"
                                            >
                                                {paymentLoading === plan.plan ? 'Opening Paystack…' : 'Pay with Paystack'}
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ) : modalLoading ? (
                            <div className="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">Loading…</div>
                        ) : modalStaff.length === 0 ? (
                            <div className="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">No records found.</div>
                        ) : (
                            <div className="mt-4 max-h-80 overflow-y-auto">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                                        <tr>
                                            <th className="px-3 py-2">Staff</th>
                                            <th className="px-3 py-2">Dept</th>
                                            <th className="px-3 py-2">In</th>
                                            <th className="px-3 py-2">Out</th>
                                            <th className="px-3 py-2">Hours</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {modalStaff.map((s) => (
                                            <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-800">
                                                <td className="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">{s.full_name}</td>
                                                <td className="px-3 py-2 text-slate-500 dark:text-slate-400">{s.department}</td>
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

            <div className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div className="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">
                    Recent scans
                </div>
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {recentScans.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                            <div>
                                <div className="font-semibold text-slate-900 dark:text-slate-100">{row.staff?.full_name}</div>
                                <div className="text-xs text-slate-500 dark:text-slate-400">
                                    {row.staff?.department} · {row.staff?.staff_id}
                                </div>
                            </div>
                            <div className="text-right text-xs text-slate-500 dark:text-slate-400">
                                <div>{formatTime(row.clock_in)}</div>
                                <div>{row.clock_out ? 'Completed' : 'On site'}</div>
                            </div>
                        </li>
                    ))}
                    {recentScans.length === 0 && (
                        <li className="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No scans recorded yet today.</li>
                    )}
                </ul>
            </div>
        </div>
    );
}
