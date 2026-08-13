import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../AuthContext';

export default function ProtectedRoute({ permission, children }) {
    const { user, loading, can } = useAuth();
    const location = useLocation();

    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-50 dark:bg-slate-950">
                <div className="hg-global-loading" role="status" aria-live="polite" aria-label="Loading your session">
                    <span className="hg-global-loading__spinner" aria-hidden="true" />
                    <span>Loading</span>
                </div>
            </div>
        );
    }
    if (!user) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }
    if (user.must_change_password && can('password.change_self') && location.pathname !== '/change-password') {
        return <Navigate to="/change-password" replace />;
    }
    if (permission && !can(permission)) {
        return <Navigate to="/dashboard" replace />;
    }

    return children;
}
