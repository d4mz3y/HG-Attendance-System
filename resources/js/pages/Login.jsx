import React, { useState, useEffect } from 'react';
import { useNavigate, Navigate } from 'react-router-dom';
import api from '../api';
import { useToast } from '../components/Toast';

export default function Login() {
    const navigate = useNavigate();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [remember, setRemember] = useState(() => localStorage.getItem('hg_remember') === '1');
    const { addToast } = useToast();

    if (localStorage.getItem('hg_token')) {
        return <Navigate to="/dashboard" replace />;
    }

    const submit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            const { data } = await api.post('/login', { username, password });
            localStorage.setItem('hg_token', data.token);
            if (remember) {
                localStorage.setItem('hg_remember', '1');
            } else {
                localStorage.removeItem('hg_remember');
            }
            navigate('/dashboard');
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

    useEffect(() => {
        if (remember) {
            const saved = localStorage.getItem('hg_username');
            if (saved) setUsername(saved);
        }
    }, [remember]);

    useEffect(() => {
        if (remember) {
            localStorage.setItem('hg_username', username);
        }
    }, [username, remember]);

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <form
                onSubmit={submit}
                className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
            >
                <div className="flex flex-col items-center">
                    <img src="/logo.png" alt="Hogan Guards" className="mb-4 h-16 w-16 rounded-lg object-contain" />
                    <h1 className="text-2xl font-bold text-slate-900">Admin sign in</h1>
                    <p className="mt-1 text-sm text-slate-500">Hogan Guards attendance console</p>
                </div>

                {error && (
                    <div className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{String(error)}</div>
                )}

                <label className="mt-6 block text-sm font-medium text-slate-700">
                    Username
                    <input
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                        value={username}
                        onChange={(e) => setUsername(e.target.value)}
                        autoComplete="username"
                        required
                    />
                </label>

                <label className="mt-4 block text-sm font-medium text-slate-700">
                    Password
                    <input
                        type="password"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        autoComplete="current-password"
                        required
                    />
                </label>

                <label className="mt-4 flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                    Remember me
                </label>

                <button
                    type="submit"
                    disabled={loading}
                    className="mt-4 w-full rounded-lg bg-hg-blue py-2.5 text-sm font-semibold text-white hover:bg-hg-navy disabled:opacity-60"
                >
                    {loading ? 'Signing in…' : 'Sign in'}
                </button>

                <p className="mt-6 text-center text-xs text-slate-500">
                    Kiosk scan terminal:{' '}
                    <a className="font-semibold text-hg-blue underline" href="/scan">
                        Open /scan
                    </a>
                </p>
            </form>
        </div>
    );
}
