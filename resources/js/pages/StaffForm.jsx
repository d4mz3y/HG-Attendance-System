import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import { downloadStaffCodePng } from '../staffCodeDownload';
import { useToast } from '../components/Toast';
import { localDateISO } from '../localDate';

const empty = {
    staff_id: '',
    company: 'Hogan Guards',
    full_name: '',
    department: 'Operations',
    job_title: '',
    branch: 'Lagos (HQ)',
    employment_status: 'Active',
    employment_start_date: localDateISO(),
    employment_end_date: '',
    employment_change_reason: '',
    assignment_effective_date: localDateISO(),
    assignment_change_reason: '',
};

function optionsIncludingCurrent(options, currentValue, fallback = []) {
    const unique = [];
    const seen = new Set();

    for (const value of [...(options.length ? options : fallback), currentValue]) {
        const trimmed = String(value ?? '').trim();
        const key = trimmed.toLocaleLowerCase();
        if (trimmed && !seen.has(key)) {
            seen.add(key);
            unique.push(trimmed);
        }
    }

    return unique;
}

function validationMessage(error, fallback) {
    const messages = Object.values(error?.response?.data?.errors ?? {}).flat();

    return messages.find(Boolean) ?? error?.response?.data?.message ?? fallback;
}

export default function StaffForm() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(id);
    const [form, setForm] = useState(empty);
    const [departments, setDepartments] = useState([]);
    const [branches, setBranches] = useState([]);
    const [companies, setCompanies] = useState([]);
    const [photo, setPhoto] = useState(null);
    const [loading, setLoading] = useState(false);
    const [createdStaff, setCreatedStaff] = useState(null);
    const [employmentHistory, setEmploymentHistory] = useState([]);
    const [assignmentHistory, setAssignmentHistory] = useState([]);
    const { addToast } = useToast();

    useEffect(() => {
        api.get('/lookups/departments').then((r) => setDepartments(r.data));
        api.get('/lookups/branches').then((r) => setBranches(r.data));
        api.get('/lookups/companies').then((r) => setCompanies(r.data));
    }, []);

    useEffect(() => {
        if (!isEdit) {
            setCreatedStaff(null);
        }
    }, [isEdit]);

    useEffect(() => {
        if (!isEdit) {
            return;
        }
        api.get(`/staff/${id}`).then((r) => {
            const s = r.data;
            setForm({
                staff_id: s.staff_id,
                company: s.company ?? 'Hogan Guards',
                full_name: s.full_name,
                department: s.department,
                job_title: s.job_title ?? '',
                branch: s.branch ?? 'Lagos (HQ)',
                employment_status: s.employment_status,
                employment_start_date: s.employment_start_date?.slice(0, 10) ?? '',
                employment_end_date: s.employment_end_date?.slice(0, 10) ?? '',
                employment_change_reason: '',
                assignment_effective_date: localDateISO(),
                assignment_change_reason: '',
            });
        });
        api.get(`/staff/${id}/employment-history`)
            .then((response) => setEmploymentHistory(Array.isArray(response.data) ? response.data : []))
            .catch(() => setEmploymentHistory([]));
        api.get(`/staff/${id}/assignment-history`)
            .then((response) => setAssignmentHistory(Array.isArray(response.data) ? response.data : []))
            .catch(() => setAssignmentHistory([]));
    }, [id, isEdit]);

    useEffect(() => {
        if (isEdit || createdStaff) {
            return;
        }
        api.get('/staff/next-id', { params: { department: form.department, branch: form.branch, company: form.company } })
            .then((r) => setForm((f) => ({ ...f, staff_id: r.data.staff_id })))
            .catch(() => {});
    }, [form.department, form.branch, form.company, isEdit, createdStaff]);

    const change = (e) => {
        const { name, value } = e.target;
        if (name === 'employment_status') {
            setForm((current) => {
                if (value === 'Active') {
                    let nextStart = current.employment_start_date;
                    if (current.employment_status === 'Inactive' && current.employment_end_date) {
                        const date = new Date(`${current.employment_end_date}T12:00:00`);
                        date.setDate(date.getDate() + 1);
                        nextStart = localDateISO(date);
                    }

                    return { ...current, employment_status: value, employment_start_date: nextStart, employment_end_date: '' };
                }

                return { ...current, employment_status: value, employment_end_date: current.employment_end_date || localDateISO() };
            });
            return;
        }
        setForm((f) => ({ ...f, [name]: value }));
    };

    const regenerateId = async () => {
        const { data } = await api.get('/staff/next-id', {
            params: { department: form.department, branch: form.branch, company: form.company },
        });
        setForm((f) => ({ ...f, staff_id: data.staff_id }));
    };

    const submit = async (e) => {
        e.preventDefault();
        setLoading(true);
        try {
            const fd = new FormData();
            Object.entries(form).forEach(([k, v]) => fd.append(k, v));
            if (photo) {
                fd.append('photo', photo);
            }
            if (isEdit) {
                await api.put(`/staff/${id}`, fd);
                navigate('/staff');
            } else {
                const { data } = await api.post('/staff', fd);
                setCreatedStaff({ id: data.id, staff_id: data.staff_id });
            }
        } catch (err) {
            addToast(validationMessage(err, 'Unable to save staff'), 'error');
        } finally {
            setLoading(false);
        }
    };

    const pullCode = async (staffPk, kind) => {
        try {
            await downloadStaffCodePng(staffPk, kind);
        } catch {
            addToast('Unable to download code image.', 'error');
        }
    };

    const deptOptions = optionsIncludingCurrent(
        departments,
        form.department,
        ['Board of Directors', 'Management', 'Operations', 'Admin', 'Finance', 'Security'],
    );
    const companyOptions = optionsIncludingCurrent(companies, form.company, ['Hogan Guards']);
    const branchOptions = optionsIncludingCurrent(branches, form.branch, ['Lagos (HQ)']);

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">{isEdit ? 'Edit staff' : 'Add staff'}</h1>
                <p className="text-sm text-slate-500">
                    Staff ID format: <span className="font-mono">HGL/LA/OPS/003</span> (company / branch / department / number).
                    QR and barcode encode this exact ID.
                </p>
                {isEdit && id && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => pullCode(id, 'qr')}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                        >
                            Download QR
                        </button>
                        <button
                            type="button"
                            onClick={() => pullCode(id, 'barcode')}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                        >
                            Download barcode
                        </button>
                    </div>
                )}
            </div>

            {createdStaff && (
                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                    <p className="font-semibold">Staff saved ({createdStaff.staff_id})</p>
                    <p className="mt-1 text-emerald-900">Printable codes use the same ID the kiosk scanner expects.</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => pullCode(createdStaff.id, 'qr')}
                            className="rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-900"
                        >
                            Download QR
                        </button>
                        <button
                            type="button"
                            onClick={() => pullCode(createdStaff.id, 'barcode')}
                            className="rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-900"
                        >
                            Download barcode
                        </button>
                        <button
                            type="button"
                            onClick={() => navigate('/staff')}
                            className="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100"
                        >
                            Back to directory
                        </button>
                    </div>
                </div>
            )}

            <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block text-sm font-medium text-slate-700">
                        Company
                        <select
                            id="company"
                            name="company"
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                            value={form.company}
                            onChange={change}
                        >
                            {companyOptions.map((c) => (
                                <option key={c} value={c}>{c}</option>
                            ))}
                        </select>
                    </label>

                    <label className="block text-sm font-medium text-slate-700">
                        Department
                        <select
                            id="department"
                            name="department"
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                            value={form.department}
                            onChange={change}
                        >
                            {deptOptions.map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block text-sm font-medium text-slate-700">
                        Branch
                        <select
                            id="branch"
                            name="branch"
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                            value={form.branch}
                            onChange={change}
                        >
                            {branchOptions.map((b) => (
                                <option key={b} value={b}>{b}</option>
                            ))}
                        </select>
                    </label>
                </div>

                <label className="block text-sm font-medium text-slate-700">
                    Staff ID (barcode / QR)
                    <div className="mt-1 flex gap-2">
                        <input
                            id="staff_id"
                            name="staff_id"
                            className="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"
                            value={form.staff_id}
                            onChange={change}
                            required
                        />
                        {!isEdit && !createdStaff && (
                            <button
                                type="button"
                                onClick={regenerateId}
                                className="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Regenerate
                            </button>
                        )}
                    </div>
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block text-sm font-medium text-slate-700">Employment start date<input type="date" name="employment_start_date" value={form.employment_start_date} onChange={change} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" /></label>
                    <label className="block text-sm font-medium text-slate-700">Employment end date<input type="date" name="employment_end_date" value={form.employment_end_date} onChange={change} disabled={form.employment_status === 'Active'} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 disabled:opacity-50" /></label>
                </div>
                {isEdit && <label className="block text-sm font-medium text-slate-700">Reason for employment change<input name="employment_change_reason" value={form.employment_change_reason} onChange={change} maxLength={500} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Required operational context for the history log" /></label>}

                {isEdit && (
                    <div className="grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
                        <label className="block text-sm font-medium text-slate-700">
                            Assignment effective date
                            <input type="date" name="assignment_effective_date" value={form.assignment_effective_date} max={localDateISO()} onChange={change} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </label>
                        <label className="block text-sm font-medium text-slate-700">
                            Reason for company, branch, department, or title change
                            <input name="assignment_change_reason" value={form.assignment_change_reason} onChange={change} maxLength={500} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Required when the assignment changes" />
                        </label>
                    </div>
                )}

                <label className="block text-sm font-medium text-slate-700">
                    Full name
                    <input
                        id="full_name"
                        name="full_name"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                        value={form.full_name}
                        onChange={change}
                        required
                    />
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Job title
                    <input
                        id="job_title"
                        name="job_title"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                        value={form.job_title}
                        onChange={change}
                    />
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Employment status
                    <select
                        id="employment_status"
                        name="employment_status"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                        value={form.employment_status}
                        onChange={change}
                    >
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </label>

                <label className="block text-sm font-medium text-slate-700">
                    Photo
                    <div className="mt-1 flex items-center gap-3">
                        <input
                            id="photo"
                            name="photo"
                            type="file"
                            accept="image/*"
                            className="block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-800"
                            onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
                        />
                    </div>
                </label>

                <div className="flex gap-3 pt-2">
                    <button
                        type="submit"
                        disabled={loading || (!isEdit && createdStaff)}
                        className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                    >
                        {loading ? 'Saving…' : 'Save'}
                    </button>
                    <button
                        type="button"
                        onClick={() => navigate('/staff')}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800"
                    >
                        Cancel
                    </button>
                </div>
            </form>

            {isEdit && employmentHistory.length > 0 && (
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-bold text-slate-900">Employment history</h2>
                    <ol className="mt-3 divide-y divide-slate-100">
                        {employmentHistory.map((item) => (
                            <li key={item.id} className="py-3 text-sm">
                                <div className="flex flex-wrap justify-between gap-2"><span className="font-semibold">{item.status}</span><span>{item.effective_from?.slice(0, 10)}{item.effective_to ? ` to ${item.effective_to.slice(0, 10)}` : ' onward'}</span></div>
                                <div className="mt-1 text-xs text-slate-500">{item.reason || 'No reason supplied'} · {item.changer?.username || 'System'}</div>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {isEdit && assignmentHistory.length > 0 && (
                <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 className="font-bold text-slate-900">Organizational assignment history</h2>
                    <ol className="mt-3 divide-y divide-slate-100">
                        {assignmentHistory.map((item) => (
                            <li key={item.id} className="py-3 text-sm">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <span className="font-semibold">{item.company} · {item.branch} · {item.department}</span>
                                    <span>{item.effective_from?.slice(0, 10)}{item.effective_to ? ` to ${item.effective_to.slice(0, 10)}` : ' onward'}</span>
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    {item.job_title || 'No job title'} · {item.reason || 'No reason supplied'} · {item.changer?.username || 'System'}
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>
            )}
        </div>
    );
}
