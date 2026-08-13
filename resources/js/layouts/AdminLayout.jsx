import React, { useMemo, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../AuthContext';

const linkClass = ({ isActive }) =>
    `flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${
        isActive
            ? 'bg-hg-blue text-white shadow-sm'
            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white'
    }`;

const NAVIGATION = [
    ['/dashboard', 'Dashboard', 'dashboard.view'],
    ['/staff', 'Staff', 'staff.view'],
    ['/schedules', 'Schedules', 'schedule.view'],
    ['/leaves', 'Leaves', 'leave.view'],
    ['/attendance', 'Attendance', 'attendance.view'],
    ['/reports', 'Reports', 'report.view'],
    ['/audit-log', 'Audit log', 'audit.view'],
    ['/organization', 'Organization', 'organization.view'],
    ['/public-holidays', 'Public holidays', 'holiday.view'],
    ['/users', 'Portal users', 'users.manage'],
    ['/devices', 'Scan devices', 'devices.manage'],
    ['/settings', 'Settings', 'settings.view'],
];

export default function AdminLayout() {
    const navigate = useNavigate();
    const { user, can, signOut } = useAuth();
    const [theme, setTheme] = useState(() => localStorage.getItem('hg_theme_override') || 'default');

    const dark = useMemo(() => {
        if (theme === 'dark') return true;
        if (theme === 'light') return false;
        return Boolean(user?.dark_mode_default);
    }, [theme, user]);

    const toggleTheme = () => {
        const next = dark ? 'light' : 'dark';
        localStorage.setItem('hg_theme_override', next);
        setTheme(next);
        document.documentElement.classList.toggle('dark', !dark);
        document.documentElement.style.colorScheme = !dark ? 'dark' : 'light';
    };

    const logout = async () => {
        await signOut();
        navigate('/login', { replace: true });
    };

    return (
        <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 md:h-screen md:flex-row">
            <aside className="shrink-0 border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 md:h-screen md:w-64 md:overflow-y-auto md:border-b-0 md:border-r">
                <div className="px-4 py-4">
                    <div className="flex items-center gap-3">
                        <img src="/logo.png" alt="Hogan Guards" className="h-12 w-12 rounded-lg object-contain" />
                        <div><div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Hogan Guards</div><div className="text-lg font-bold text-slate-900 dark:text-white">Attendance Portal</div></div>
                    </div>
                    <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <span className="font-semibold">{user.username}</span> · {user.role.replaceAll('_', ' ')}
                    </div>
                </div>
                <nav className="flex flex-wrap gap-1 px-2 pb-4 text-slate-600 dark:text-slate-300 md:flex-col">
                    {NAVIGATION.filter(([, , permission]) => can(permission)).map(([to, label]) => <NavLink key={to} to={to} className={linkClass}>{label}</NavLink>)}
                    {can('scan.use') && <NavLink to="/scan" className={linkClass}>Scan terminal</NavLink>}
                    {can('password.change_self') && <NavLink to="/change-password" className={linkClass}>Change password</NavLink>}
                    <button type="button" onClick={toggleTheme} className="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">{dark ? 'Use light mode' : 'Use dark mode'}</button>
                    <button type="button" onClick={logout} className="mt-2 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/50">Log out</button>
                </nav>
            </aside>
            <main className="flex-1 overflow-y-auto p-4 md:p-8"><Outlet /></main>
        </div>
    );
}
