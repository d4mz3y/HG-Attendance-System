import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import api from '../api';
import { downloadStaffCodePng } from '../staffCodeDownload';
import { useToast } from '../components/Toast';

const empty = {
    staff_id: '',
    company: 'Hogan Guards',
    full_name: '',
    department: 'Operations',
    job_title: '',
    branch: 'Lagos (HQ)',
    employment_status: 'Active',
};

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
                branch: s.branch ?? 'HQ',
                employment_status: s.employment_status,
            });
        });
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
            const msg = err.response?.data?.message ?? 'Unable to save staff';
            addToast(msg, 'error');
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

    const deptOptions = departments.length
        ? departments
        : ['Board of Directors', 'Management', 'Operations', 'Admin', 'Finance', 'Security'];

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
                            {companies.map((c) => (
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
                            {branches.map((b) => (
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
        </div>
    );
}
