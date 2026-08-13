import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from './api';
import { clearAuthToken, getAuthToken, hasAuthToken, storeAuthToken } from './authToken';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(hasAuthToken);

    const refresh = useCallback(async () => {
        if (!getAuthToken()) {
            setUser(null);
            setLoading(false);
            return null;
        }

        setLoading(true);
        try {
            const { data } = await api.get('/user');
            setUser(data.user);
            return data.user;
        } catch {
            clearAuthToken();
            setUser(null);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
        const expired = () => setUser(null);
        window.addEventListener('hg:auth-expired', expired);
        return () => window.removeEventListener('hg:auth-expired', expired);
    }, [refresh]);

    const signIn = useCallback((payload, remember = false) => {
        storeAuthToken(payload.token, remember);
        setUser(payload.user);
    }, []);

    const signOut = useCallback(async () => {
        try {
            await api.post('/logout');
        } catch {
            // Local logout must still complete if the token already expired.
        }
        clearAuthToken();
        setUser(null);
    }, []);

    const can = useCallback((permission) => {
        const permissions = user?.permissions ?? [];
        return permissions.includes('*') || permissions.includes(permission);
    }, [user]);

    const value = useMemo(() => ({ user, loading, can, refresh, signIn, signOut }), [user, loading, can, refresh, signIn, signOut]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const value = useContext(AuthContext);
    if (!value) {
        throw new Error('useAuth must be used inside AuthProvider');
    }
    return value;
}
