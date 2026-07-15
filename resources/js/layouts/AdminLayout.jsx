import React, { useEffect, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import api from '../api';

const linkClass = ({ isActive }) =>
    `flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${
        isActive ? 'bg-hg-blue text-white' : 'text-slate-600 hover:bg-slate-100'
    }`;

export default function AdminLayout() {
    const navigate = useNavigate();
    const [ready, setReady] = useState(false);

    useEffect(() => {
        const token = localStorage.getItem('hg_token');
        if (!token) {
            navigate('/login', { replace: true });
            return;
        }
        api.get('/user')
            .catch(() => {
                localStorage.removeItem('hg_token');
                navigate('/login', { replace: true });
            })
            .finally(() => setReady(true));
    }, [navigate]);

    const logout = async () => {
        try {
            await api.post('/logout');
        } catch {
            //
        }
        localStorage.removeItem('hg_token');
        navigate('/login');
    };

    if (!ready) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-50 text-slate-600">
                Loading…
            </div>
        );
    }

    return (
        <div className="min-h-screen md:flex bg-slate-50 text-slate-900">
            <aside className="border-b md:w-64 md:border-b-0 md:border-r md:min-h-screen border-slate-200 bg-white">
                <div className="px-4 py-4 md:block">
                    <div className="flex items-center gap-3">
                        <img src="/logo.svg" alt="Hogan Guards" className="h-8 w-8" />
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Hogan Guards
                            </div>
                            <div className="text-lg font-bold text-slate-900">Attendance Portal</div>
                        </div>
                    </div>
                </div>
                <nav className="flex flex-wrap gap-1 px-2 pb-4 md:flex-col text-slate-600">
                    <NavLink to="/dashboard" className={linkClass}>Dashboard</NavLink>
                    <NavLink to="/staff" className={linkClass}>Staff</NavLink>
                    <NavLink to="/schedules" className={linkClass}>Schedules</NavLink>
                    <NavLink to="/leaves" className={linkClass}>Leaves</NavLink>
                    <NavLink to="/attendance" className={linkClass}>Attendance</NavLink>
                    <NavLink to="/reports" className={linkClass}>Reports</NavLink>
                    <NavLink to="/audit-log" className={linkClass}>Audit log</NavLink>
                    <NavLink to="/organization" className={linkClass}>Organization</NavLink>
                    <NavLink to="/public-holidays" className={linkClass}>Public holidays</NavLink>
                    <NavLink to="/settings" className={linkClass}>Settings</NavLink>
                    <NavLink to="/scan" className={linkClass}>Scan terminal</NavLink>
                    <button type="button" onClick={logout} className="mt-2 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">
                        Log out
                    </button>
                </nav>
            </aside>
            <main className="flex-1 p-4 md:p-8">
                <Outlet />
            </main>
        </div>
    );
}
