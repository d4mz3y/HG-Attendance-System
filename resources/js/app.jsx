import './bootstrap';
import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter, RouterProvider, Navigate } from 'react-router-dom';
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

const router = createBrowserRouter([
    { path: '/', element: <Navigate to="/scan" replace /> },
    { path: '/scan', element: <Scan /> },
    { path: '/login', element: <Login /> },
    {
        element: <AdminLayout />,
        children: [
            { path: 'dashboard', element: <Dashboard /> },
            { path: 'staff', element: <StaffList /> },
            { path: 'staff/new', element: <StaffForm /> },
            { path: 'staff/:id/edit', element: <StaffForm /> },
            { path: 'schedules', element: <Schedules /> },
            { path: 'leaves', element: <Leaves /> },
            { path: 'attendance', element: <Attendance /> },
            { path: 'reports', element: <Reports /> },
            { path: 'audit-log', element: <AuditLog /> },
            { path: 'organization', element: <Organization /> },
            { path: 'public-holidays', element: <PublicHolidays /> },
            { path: 'settings', element: <Settings /> },
        ],
    },
]);

createRoot(document.getElementById('app')).render(
    <React.StrictMode>
        <RouterProvider router={router} />
    </React.StrictMode>
);
