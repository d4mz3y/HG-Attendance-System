import React, { useState } from 'react';
import { useLocation, useNavigate, Navigate } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../AuthContext';
import { useToast } from '../components/Toast';
import PasswordVisibilityButton from '../components/PasswordVisibilityButton';

export default function Login() {
    const navigate = useNavigate();
    const location = useLocation();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [remember, setRemember] = useState(() => Boolean(localStorage.getItem('hg_remember_token')));
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const { addToast } = useToast();
    const { user, loading: authLoading, signIn } = useAuth();

    const mustChangeOwnPassword = user?.must_change_password
        && (user.permissions ?? []).some((permission) => permission === '*' || permission === 'password.change_self');

    if (!authLoading && user) {
        return <Navigate to={mustChangeOwnPassword ? '/change-password' : '/dashboard'} replace />;
    }

    const submit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            const { data } = await api.post('/login', { username, password, remember });
            signIn(data, remember);
            const mustChangePassword = data.user.must_change_password
                && (data.user.permissions ?? []).some((permission) => permission === '*' || permission === 'password.change_self');
            navigate(mustChangePassword ? '/change-password' : (location.state?.from ?? '/dashboard'), { replace: true });
        } catch (err) {
            const d = err.response?.data;
            const msg =
                d?.errors?.username?.[0] ??
                d?.errors?.password?.[0] ??
                d?.message ??
                'Login failed';
            setError(msg);
            addToast(msg, 'error');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <form
                onSubmit={submit}
                className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-700 dark:bg-slate-900"
            >
                <div className="flex flex-col items-center">
                    <img src="/logo.png" alt="Hogan Guards" className="mb-4 h-16 w-16 rounded-lg object-contain" />
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Admin sign in</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Hogan Guards attendance console</p>
                </div>

                {error && (
                    <div role="alert" className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-200">{String(error)}</div>
                )}

                <label className="mt-6 block text-sm font-medium text-slate-700 dark:text-slate-200">
                    Username
                    <input
                        id="username"
                        name="username"
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                        value={username}
                        onChange={(e) => { setUsername(e.target.value); setError(''); }}
                        autoComplete="username"
                        maxLength={64}
                        required
                    />
                </label>

                <label className="mt-4 block text-sm font-medium text-slate-700 dark:text-slate-200">
                    Password
                    <div className="relative mt-1">
                        <input
                            id="password"
                            name="password"
                            type={showPassword ? 'text' : 'password'}
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 pr-16 text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                            value={password}
                            onChange={(e) => { setPassword(e.target.value); setError(''); }}
                            autoComplete="current-password"
                            maxLength={64}
                            required
                        />
                        <PasswordVisibilityButton visible={showPassword} onClick={() => setShowPassword((visible) => !visible)} />
                    </div>
                </label>

                <label className="mt-4 flex cursor-pointer items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} className="mt-0.5 rounded border-slate-300 text-hg-blue focus:ring-hg-blue dark:border-slate-600 dark:bg-slate-950" />
                    <span><span className="font-semibold text-slate-800 dark:text-slate-100">Remember me</span><span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">Only use this on a private, trusted computer.</span></span>
                </label>

                <button
                    type="submit"
                    disabled={loading}
                    className="mt-4 w-full rounded-lg bg-hg-blue py-2.5 text-sm font-semibold text-white hover:bg-hg-navy disabled:opacity-60"
                >
                    {loading ? 'Signing in…' : 'Sign in'}
                </button>

                <p className="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    Kiosk scan terminal:{' '}
                    <a className="font-semibold text-hg-blue underline" href="/scan">
                        Open /scan
                    </a>
                </p>
            </form>
        </div>
    );
}
