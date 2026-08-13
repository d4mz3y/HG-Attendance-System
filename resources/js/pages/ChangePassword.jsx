import React, { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api';
import { useAuth } from '../AuthContext';
import { useToast } from '../components/Toast';
import PasswordVisibilityButton from '../components/PasswordVisibilityButton';

const MAX_PASSWORD_CHARACTERS = 64;
const MAX_PASSWORD_BYTES = 72;

function PasswordField({ id, label, value, onChange, autoComplete, visible, onVisibilityChange, error, describedBy, minLength }) {
    const errorId = `${id}-error`;
    const description = [describedBy, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-slate-700 dark:text-slate-200">{label}</label>
            <div className="relative mt-1">
                <input
                    id={id}
                    name={id}
                    type={visible ? 'text' : 'password'}
                    autoComplete={autoComplete}
                    minLength={minLength}
                    maxLength={MAX_PASSWORD_CHARACTERS}
                    required
                    aria-invalid={Boolean(error)}
                    aria-describedby={description}
                    className={`w-full rounded-lg border bg-white px-3 py-2 pr-16 text-slate-900 outline-none focus:ring-2 focus:ring-hg-blue dark:bg-slate-950 dark:text-slate-100 ${error ? 'border-red-500 dark:border-red-400' : 'border-slate-300 dark:border-slate-600'}`}
                    value={value}
                    onChange={onChange}
                />
                <PasswordVisibilityButton visible={visible} onClick={onVisibilityChange} label={label.toLowerCase()} />
            </div>
            {error && <p id={errorId} role="alert" className="mt-1 text-sm text-red-700 dark:text-red-300">{error}</p>}
        </div>
    );
}

export default function ChangePassword() {
    const navigate = useNavigate();
    const { user, refresh, signOut } = useAuth();
    const { addToast } = useToast();
    const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [visible, setVisible] = useState({ current: false, password: false, confirmation: false });
    const [serverErrors, setServerErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);

    const passwordBytes = useMemo(() => new TextEncoder().encode(form.password).length, [form.password]);
    const passwordMismatch = form.password_confirmation.length > 0 && form.password !== form.password_confirmation;
    const byteLimitError = passwordBytes > MAX_PASSWORD_BYTES
        ? `Password must not exceed ${MAX_PASSWORD_BYTES} bytes.`
        : '';
    const passwordError = byteLimitError || serverErrors.password?.[0] || '';
    const confirmationError = passwordMismatch ? 'New passwords do not match.' : '';

    const update = (field) => (event) => {
        setForm((current) => ({ ...current, [field]: event.target.value }));
        setServerErrors({});
        setFormError('');
    };

    const toggleVisibility = (field) => () => {
        setVisible((current) => ({ ...current, [field]: !current[field] }));
    };

    const submit = async (event) => {
        event.preventDefault();
        setFormError('');
        setServerErrors({});

        const clientError = passwordMismatch
            ? 'New passwords do not match.'
            : byteLimitError;
        if (clientError) {
            setFormError(clientError);
            addToast(clientError, 'error');
            return;
        }

        setSaving(true);
        try {
            await api.post('/change-password', form);
            await refresh();
            addToast('Password changed.', 'success');
            navigate('/dashboard', { replace: true });
        } catch (error) {
            const errors = error.response?.data?.errors ?? {};
            const message = Object.values(errors).flat()[0] ?? error.response?.data?.message ?? 'Unable to change password.';
            setServerErrors(errors);
            setFormError(message);
            addToast(message, 'error');
        } finally {
            setSaving(false);
        }
    };

    if (!user) return null;

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <form onSubmit={submit} className="w-full max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Change your password</h1>
                    <p id="password-requirements" className="mt-1 text-sm text-slate-500 dark:text-slate-400">Use 12–64 characters with upper- and lowercase letters, a number, and a symbol.</p>
                </div>
                {user.must_change_password && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">Your temporary password must be changed before you continue.</p>}
                {formError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-200">{formError}</p>}
                <PasswordField
                    id="current_password"
                    label="Current password"
                    value={form.current_password}
                    onChange={update('current_password')}
                    autoComplete="current-password"
                    visible={visible.current}
                    onVisibilityChange={toggleVisibility('current')}
                    error={serverErrors.current_password?.[0]}
                    minLength={undefined}
                />
                <PasswordField
                    id="password"
                    label="New password"
                    value={form.password}
                    onChange={update('password')}
                    autoComplete="new-password"
                    visible={visible.password}
                    onVisibilityChange={toggleVisibility('password')}
                    error={passwordError}
                    describedBy="password-requirements"
                    minLength={12}
                />
                <PasswordField
                    id="password_confirmation"
                    label="Confirm new password"
                    value={form.password_confirmation}
                    onChange={update('password_confirmation')}
                    autoComplete="new-password"
                    visible={visible.confirmation}
                    onVisibilityChange={toggleVisibility('confirmation')}
                    error={confirmationError}
                    describedBy="password-requirements"
                    minLength={12}
                />
                <button disabled={saving} className="w-full rounded-lg bg-slate-900 py-2.5 font-semibold text-white hover:bg-slate-800 disabled:opacity-50 dark:bg-hg-blue dark:hover:bg-sky-700">{saving ? 'Saving…' : 'Change password'}</button>
                {!user.must_change_password && <button type="button" onClick={() => navigate(-1)} className="w-full text-sm text-slate-600 underline dark:text-slate-300">Cancel</button>}
                <button type="button" onClick={signOut} className="w-full text-sm text-red-600 underline dark:text-red-300">Sign out</button>
            </form>
        </div>
    );
}
