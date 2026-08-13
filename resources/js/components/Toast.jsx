import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

const TYPES = {
    success: { bg: 'bg-emerald-600', icon: '✓' },
    error: { bg: 'bg-red-600', icon: '✕' },
    warning: { bg: 'bg-amber-500', icon: '⚠' },
    info: { bg: 'bg-hg-blue', icon: 'ℹ' },
};

const ToastContext = createContext(null);

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const idRef = useRef(0);

    const addToast = useCallback((message, type = 'info', duration = 4000) => {
        const id = ++idRef.current;
        setToasts((prev) => [...prev, { id, message, type, duration }]);
        return id;
    }, []);

    const removeToast = useCallback((id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    }, []);

    const value = useMemo(() => ({ addToast, removeToast }), [addToast, removeToast]);

    return (
        <ToastContext.Provider value={value}>
            {children}
            <ToastContainer toasts={toasts} onRemove={removeToast} />
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast must be used within ToastProvider');
    }

    return context;
}

export function ToastContainer({ toasts, onRemove }) {
    return (
        <div className="fixed bottom-4 right-4 z-50 flex w-[min(23rem,calc(100vw-2rem))] flex-col gap-2">
            {toasts.map((toast) => (
                <Toast key={toast.id} toast={toast} onRemove={onRemove} />
            ))}
        </div>
    );
}

function Toast({ toast, onRemove }) {
    const [progress, setProgress] = useState(0);
    const style = TYPES[toast.type] || TYPES.info;

    useEffect(() => {
        if (!toast.duration || toast.duration <= 0) return;

        const start = Date.now();
        const interval = setInterval(() => {
            const elapsed = Date.now() - start;
            const completed = Math.min(100, (elapsed / toast.duration) * 100);
            setProgress(completed);

            if (completed >= 100) {
                clearInterval(interval);
                onRemove(toast.id);
            }
        }, 50);

        return () => clearInterval(interval);
    }, [toast.id, toast.duration, onRemove]);

    return (
        <div
            className={`relative flex min-h-16 cursor-pointer items-center gap-3 overflow-hidden rounded-xl ${style.bg} px-4 py-3 pr-11 text-white shadow-xl`}
            onClick={() => onRemove(toast.id)}
        >
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/20 text-sm font-bold">
                {style.icon}
            </span>
            <span className="min-w-0 flex-1 text-sm font-medium leading-5">{toast.message}</span>
            {toast.duration > 0 && (
                <div className="absolute right-3 top-3 h-5 w-5 rounded-full" aria-label="Toast dismissal timer">
                    <svg viewBox="0 0 36 36" className="h-5 w-5 -rotate-90" aria-hidden="true">
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(255,255,255,0.32)" strokeWidth="3" />
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="white" strokeWidth="3" strokeLinecap="round" strokeDasharray="100" strokeDashoffset={progress} />
                    </svg>
                </div>
            )}
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
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-700 dark:bg-slate-900">
                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">{title}</h2>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{message}</p>
                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-100 dark:hover:bg-slate-800"
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
