import React, { useEffect, useState } from 'react';
import api from '../api';
import { useAuth } from '../AuthContext';
import { useToast } from '../components/Toast';
import PasswordVisibilityButton from '../components/PasswordVisibilityButton';
import { formatDateTime } from '../timeFormat';

const ROLES = [
    ['hr_assistant', 'HR assistant'],
    ['hr', 'HR manager'],
    ['it_manager', 'IT manager'],
    ['super_admin', 'Super administrator'],
];

const EMPTY_USER_FORM = {
    username: '',
    role: 'hr_assistant',
    password: '',
    password_confirmation: '',
};

const MAX_PASSWORD_BYTES = 72;

function passwordByteError(password) {
    const bytes = new TextEncoder().encode(password).length;
    return bytes > MAX_PASSWORD_BYTES ? `Password must not exceed ${MAX_PASSWORD_BYTES} bytes.` : '';
}

function errorMessage(error, fallback) {
    return Object.values(error.response?.data?.errors ?? {}).flat()[0]
        ?? error.response?.data?.message
        ?? fallback;
}

function PasswordInput({ id, label, value, onChange, visible, onToggle, autoComplete = 'new-password', error = '' }) {
    const errorId = `${id}-error`;

    return (
        <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">
            {label}
            <div className="relative mt-1">
                <input
                    id={id}
                    name={id}
                    type={visible ? 'text' : 'password'}
                    value={value}
                    onChange={onChange}
                    autoComplete={autoComplete}
                    minLength={12}
                    maxLength={64}
                    required
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? errorId : undefined}
                    className={`w-full rounded-lg border bg-white px-3 py-2 pr-16 text-slate-900 outline-none focus:ring-2 focus:ring-hg-blue dark:bg-slate-950 dark:text-slate-100 ${error ? 'border-red-500 dark:border-red-400' : 'border-slate-300 dark:border-slate-600'}`}
                />
                <PasswordVisibilityButton visible={visible} onClick={onToggle} label={label.toLowerCase()} />
            </div>
            {error && <span id={errorId} role="alert" className="mt-1 block text-xs font-medium text-red-700 dark:text-red-300">{error}</span>}
        </label>
    );
}

export default function Users() {
    const { user: currentUser } = useAuth();
    const { addToast } = useToast();
    const [users, setUsers] = useState([]);
    const [form, setForm] = useState(EMPTY_USER_FORM);
    const [showCreatePassword, setShowCreatePassword] = useState(false);
    const [creating, setCreating] = useState(false);
    const [authEvents, setAuthEvents] = useState([]);
    const [passwordTarget, setPasswordTarget] = useState(null);
    const [passwordForm, setPasswordForm] = useState({ password: '', password_confirmation: '' });
    const [showResetPassword, setShowResetPassword] = useState(false);
    const [resetting, setResetting] = useState(false);

    const createPasswordError = passwordByteError(form.password);
    const createConfirmationError = form.password_confirmation.length > 0 && form.password !== form.password_confirmation
        ? 'Passwords do not match.'
        : '';
    const resetPasswordError = passwordByteError(passwordForm.password);
    const resetConfirmationError = passwordForm.password_confirmation.length > 0 && passwordForm.password !== passwordForm.password_confirmation
        ? 'Passwords do not match.'
        : '';

    const load = async () => {
        const [usersResponse, eventsResponse] = await Promise.all([
            api.get('/users', { params: { per_page: 100 } }),
            api.get('/auth-events', { params: { per_page: 20 } }),
        ]);
        setUsers(usersResponse.data.data ?? []);
        setAuthEvents(eventsResponse.data.data ?? []);
    };

    useEffect(() => {
        void load().catch((error) => addToast(errorMessage(error, 'Unable to load portal users.'), 'error'));
    }, [addToast]);

    const create = async (event) => {
        event.preventDefault();
        const validationError = createPasswordError || createConfirmationError;
        if (validationError) {
            addToast(validationError, 'error');
            return;
        }

        setCreating(true);
        try {
            await api.post('/users', form);
            setForm(EMPTY_USER_FORM);
            setShowCreatePassword(false);
            await load();
            addToast('Portal user created. Give the password to the user privately.', 'success');
        } catch (error) {
            addToast(errorMessage(error, 'Unable to create user.'), 'error');
        } finally {
            setCreating(false);
        }
    };

    const update = async (target, changes) => {
        try {
            await api.put(`/users/${target.id}`, changes);
            await load();
            addToast('User updated.', 'success');
        } catch (error) {
            addToast(errorMessage(error, 'Unable to update user.'), 'error');
        }
    };

    const openPasswordDialog = (target) => {
        setPasswordTarget(target);
        setPasswordForm({ password: '', password_confirmation: '' });
        setShowResetPassword(false);
    };

    const setPassword = async (event) => {
        event.preventDefault();
        if (!passwordTarget) return;
        const validationError = resetPasswordError || resetConfirmationError;
        if (validationError) {
            addToast(validationError, 'error');
            return;
        }

        setResetting(true);
        try {
            await api.post(`/users/${passwordTarget.id}/reset-password`, passwordForm);
            setPasswordTarget(null);
            setPasswordForm({ password: '', password_confirmation: '' });
            await load();
            addToast(`${passwordTarget.username}'s password was set and their other sessions were signed out.`, 'success');
        } catch (error) {
            addToast(errorMessage(error, 'Unable to set password.'), 'error');
        } finally {
            setResetting(false);
        }
    };

    const revokeSessions = async (target) => {
        if (!window.confirm(`Sign ${target.username} out of every session?`)) return;
        try {
            await api.post(`/users/${target.id}/revoke-sessions`);
            addToast('Sessions revoked.', 'success');
        } catch (error) {
            addToast(errorMessage(error, 'Unable to revoke sessions.'), 'error');
        }
    };

    const canManageTarget = (target) => target.id !== currentUser.id
        && (target.role !== 'super_admin' || currentUser.role === 'super_admin');

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Portal users</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">IT and the super administrator create accounts and set passwords for other portal users.</p>
            </div>

            <form onSubmit={create} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_210px_minmax(0,1fr)_minmax(0,1fr)_auto] dark:border-slate-700 dark:bg-slate-900">
                <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">Username
                    <input aria-label="Username" placeholder="Username" required minLength={3} maxLength={64} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} />
                </label>
                <label className="block text-sm font-medium text-slate-700 dark:text-slate-200">Role
                    <select aria-label="Role" className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })}>
                        {ROLES.filter(([role]) => currentUser.role === 'super_admin' || role !== 'super_admin').map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </label>
                <PasswordInput id="new_user_password" label="Password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} visible={showCreatePassword} onToggle={() => setShowCreatePassword((visible) => !visible)} error={createPasswordError} />
                <PasswordInput id="new_user_password_confirmation" label="Confirm password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} visible={showCreatePassword} onToggle={() => setShowCreatePassword((visible) => !visible)} error={createConfirmationError} />
                <button disabled={creating || Boolean(createPasswordError || createConfirmationError)} className="self-end rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white disabled:opacity-50 dark:bg-sky-600 dark:hover:bg-sky-500">{creating ? 'Creating…' : 'Create user'}</button>
                <p className="text-xs text-slate-500 dark:text-slate-400 xl:col-span-5">Passwords require at least 12 characters with upper- and lowercase letters, a number, and a symbol. Give the password to the user privately.</p>
            </form>

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <table className="min-w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800/70 dark:text-slate-300"><tr><th className="p-3">Username</th><th className="p-3">Role</th><th className="p-3">Status</th><th className="p-3">Last login</th><th className="p-3">Actions</th></tr></thead>
                    <tbody className="divide-y divide-slate-200 dark:divide-slate-700">
                        {users.map((target) => (
                            <tr key={target.id}>
                                <td className="p-3 font-medium text-slate-900 dark:text-slate-100">{target.username}{target.id === currentUser.id ? ' (you)' : ''}</td>
                                <td className="p-3"><select disabled={target.id === currentUser.id || (target.role === 'super_admin' && currentUser.role !== 'super_admin')} className="rounded border border-slate-300 bg-white px-2 py-1 text-slate-900 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" value={target.role} onChange={(event) => update(target, { role: event.target.value })}>{ROLES.map(([value, label]) => <option key={value} value={value} disabled={value === 'super_admin' && currentUser.role !== 'super_admin'}>{label}</option>)}</select></td>
                                <td className="p-3 text-slate-700 dark:text-slate-200">{target.is_active ? 'Active' : 'Disabled'}</td>
                                <td className="p-3 text-slate-500 dark:text-slate-400">{target.last_login_at ? formatDateTime(target.last_login_at) : 'Never'}</td>
                                <td className="p-3 whitespace-nowrap">
                                    {canManageTarget(target) && <><button type="button" onClick={() => openPasswordDialog(target)} className="text-sky-700 underline dark:text-sky-300">Set password</button><button type="button" onClick={() => revokeSessions(target)} className="ml-3 text-slate-700 underline dark:text-slate-200">Sign out</button><button type="button" onClick={() => update(target, { is_active: !target.is_active })} className={`ml-3 underline ${target.is_active ? 'text-red-600 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300'}`}>{target.is_active ? 'Disable' : 'Enable'}</button></>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div className="border-b border-slate-200 p-4 dark:border-slate-700"><h2 className="font-bold text-slate-900 dark:text-slate-100">Recent security activity</h2><p className="text-xs text-slate-500 dark:text-slate-400">Successful and failed sign-ins, password changes, and access administration.</p></div>
                <table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800/70 dark:text-slate-300"><tr><th className="p-3">Time</th><th className="p-3">Event</th><th className="p-3">Username</th><th className="p-3">IP address</th></tr></thead><tbody className="divide-y divide-slate-200 dark:divide-slate-700">{authEvents.map((event) => <tr key={event.id}><td className="p-3 text-slate-500 dark:text-slate-400">{formatDateTime(event.created_at)}</td><td className="p-3 text-slate-800 dark:text-slate-100">{event.event.replaceAll('_', ' ')}</td><td className="p-3 text-slate-800 dark:text-slate-100">{event.username || '—'}</td><td className="p-3 font-mono text-xs text-slate-600 dark:text-slate-300">{event.ip_address || '—'}</td></tr>)}</tbody></table>
            </div>

            {passwordTarget && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="set-password-title">
                    <form onSubmit={setPassword} className="w-full max-w-lg space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                        <div>
                            <h2 id="set-password-title" className="text-xl font-bold text-slate-900 dark:text-slate-100">Set password for {passwordTarget.username}</h2>
                            <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">This signs the user out of their other sessions. They do not need to change it again before working.</p>
                        </div>
                        <PasswordInput id="reset_password" label="New password" value={passwordForm.password} onChange={(event) => setPasswordForm({ ...passwordForm, password: event.target.value })} visible={showResetPassword} onToggle={() => setShowResetPassword((visible) => !visible)} error={resetPasswordError} />
                        <PasswordInput id="reset_password_confirmation" label="Confirm new password" value={passwordForm.password_confirmation} onChange={(event) => setPasswordForm({ ...passwordForm, password_confirmation: event.target.value })} visible={showResetPassword} onToggle={() => setShowResetPassword((visible) => !visible)} error={resetConfirmationError} />
                        <div className="flex flex-wrap justify-end gap-3"><button type="button" disabled={resetting} onClick={() => setPasswordTarget(null)} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 dark:border-slate-600 dark:text-slate-200">Cancel</button><button disabled={resetting || Boolean(resetPasswordError || resetConfirmationError)} className="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white disabled:opacity-50 dark:bg-sky-600">{resetting ? 'Saving…' : 'Set password'}</button></div>
                    </form>
                </div>
            )}
        </div>
    );
}
