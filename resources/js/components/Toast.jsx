import React, { useEffect, useRef, useState } from 'react';

const TYPES = {
    success: { bg: 'bg-emerald-600', icon: '✓' },
    error: { bg: 'bg-red-600', icon: '✕' },
    warning: { bg: 'bg-amber-500', icon: '⚠' },
    info: { bg: 'bg-hg-blue', icon: 'ℹ' },
};

export function useToast() {
    const [toasts, setToasts] = useState([]);
    const idRef = useRef(0);

    const addToast = (message, type = 'info', duration = 4000) => {
        const id = ++idRef.current;
        setToasts((prev) => [...prev, { id, message, type, duration }]);
        return id;
    };

    const removeToast = (id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    };

    return { toasts, addToast, removeToast };
}

export function ToastContainer({ toasts, onRemove }) {
    return (
        <div className="fixed bottom-4 left-4 z-50 flex flex-col gap-2">
            {toasts.map((toast) => (
                <Toast key={toast.id} toast={toast} onRemove={onRemove} />
            ))}
        </div>
    );
}

function Toast({ toast, onRemove }) {
    const [progress, setProgress] = useState(100);
    const style = TYPES[toast.type] || TYPES.info;

    useEffect(() => {
        if (!toast.duration || toast.duration <= 0) return;

        const start = Date.now();
        const interval = setInterval(() => {
            const elapsed = Date.now() - start;
            const remaining = Math.max(0, 100 - (elapsed / toast.duration) * 100);
            setProgress(remaining);

            if (remaining <= 0) {
                clearInterval(interval);
                onRemove(toast.id);
            }
        }, 50);

        return () => clearInterval(interval);
    }, [toast.id, toast.duration, onRemove]);

    return (
        <div
            className={`flex items-center gap-3 rounded-lg ${style.bg} px-4 py-3 text-white shadow-xl min-w-[320px] max-w-md`}
            onClick={() => onRemove(toast.id)}
        >
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/20 text-sm font-bold">
                {style.icon}
            </span>
            <span className="flex-1 text-sm font-medium">{toast.message}</span>
            <div className="h-1 w-full bg-white/30">
                <div
                    className="h-full bg-white/80 transition-all duration-75"
                    style={{ width: `${progress}%` }}
                />
            </div>
        </div>
    );
}

export function ConfirmDialog({ open, title, message, onConfirm, onCancel }) {
    if (!open) return null;

    const handleConfirm = async () => {
        await onConfirm();
        onCancel?.();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                <h2 className="text-lg font-bold text-slate-900">{title}</h2>
                <p className="mt-2 text-sm text-slate-600">{message}</p>
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={handleConfirm}
                        className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    );
}
