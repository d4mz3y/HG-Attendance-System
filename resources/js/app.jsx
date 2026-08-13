import './bootstrap';
import '../css/app.css';
import React, { useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider, Navigate, Outlet } from 'react-router-dom';
import AdminLayout from './layouts/AdminLayout';
import Scan from './pages/Scan';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import StaffList from './pages/StaffList';
import StaffForm from './pages/StaffForm';
import Attendance from './pages/Attendance';
import Reports from './pages/Reports';
import Settings from './pages/Settings';
import Leaves from './pages/Leaves';
import Schedules from './pages/Schedules';
import Organization from './pages/Organization';
import PublicHolidays from './pages/PublicHolidays';
import AuditLog from './pages/AuditLog';
import ChangePassword from './pages/ChangePassword';
import Users from './pages/Users';
import Devices from './pages/Devices';
import ProtectedRoute from './components/ProtectedRoute';
import GlobalLoadingIndicator from './components/GlobalLoadingIndicator';
import { AuthProvider } from './AuthContext';
import { useAuth } from './AuthContext';
import { ToastProvider } from './components/Toast';

function ThemeSync() {
    const { user } = useAuth();

    useEffect(() => {
        const override = localStorage.getItem('hg_theme_override');
        const dark = override === 'dark' || (override !== 'light' && Boolean(user?.dark_mode_default));

        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    }, [user?.dark_mode_default]);

    return null;
}

function AppShell() {
    return <><Outlet /><GlobalLoadingIndicator /></>;
}

const router = createBrowserRouter([
    {
        element: <AppShell />,
        children: [
            { path: '/', element: <Navigate to="/scan" replace /> },
            { path: '/scan', element: <Scan /> },
            { path: '/login', element: <Login /> },
            { path: '/change-password', element: <ProtectedRoute permission="password.change_self"><ChangePassword /></ProtectedRoute> },
            {
                element: <ProtectedRoute><AdminLayout /></ProtectedRoute>,
                children: [
                    { path: 'dashboard', element: <ProtectedRoute permission="dashboard.view"><Dashboard /></ProtectedRoute> },
                    { path: 'staff', element: <ProtectedRoute permission="staff.view"><StaffList /></ProtectedRoute> },
                    { path: 'staff/new', element: <ProtectedRoute permission="staff.manage"><StaffForm /></ProtectedRoute> },
                    { path: 'staff/:id/edit', element: <ProtectedRoute permission="staff.manage"><StaffForm /></ProtectedRoute> },
                    { path: 'schedules', element: <ProtectedRoute permission="schedule.view"><Schedules /></ProtectedRoute> },
                    { path: 'leaves', element: <ProtectedRoute permission="leave.view"><Leaves /></ProtectedRoute> },
                    { path: 'attendance', element: <ProtectedRoute permission="attendance.view"><Attendance /></ProtectedRoute> },
                    { path: 'reports', element: <ProtectedRoute permission="report.view"><Reports /></ProtectedRoute> },
                    { path: 'audit-log', element: <ProtectedRoute permission="audit.view"><AuditLog /></ProtectedRoute> },
                    { path: 'organization', element: <ProtectedRoute permission="organization.view"><Organization /></ProtectedRoute> },
                    { path: 'public-holidays', element: <ProtectedRoute permission="holiday.view"><PublicHolidays /></ProtectedRoute> },
                    { path: 'settings', element: <ProtectedRoute permission="settings.view"><Settings /></ProtectedRoute> },
                    { path: 'users', element: <ProtectedRoute permission="users.manage"><Users /></ProtectedRoute> },
                    { path: 'devices', element: <ProtectedRoute permission="devices.manage"><Devices /></ProtectedRoute> },
                ],
            },
        ],
    },
]);

createRoot(document.getElementById('app')).render(
    <React.StrictMode>
        <ToastProvider>
            <AuthProvider>
                <ThemeSync />
                <RouterProvider router={router} />
            </AuthProvider>
        </ToastProvider>
    </React.StrictMode>
);
