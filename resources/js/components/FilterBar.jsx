export default function FilterBar({ children, onApply, applyLabel = 'Apply' }) {
    return (
        <div className="space-y-3">
            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-2 lg:grid-cols-4">
                {children}
            </div>
            <div className="flex justify-end">
                <button
                    type="button"
                    onClick={onApply}
                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
                >
                    {applyLabel}
                </button>
            </div>
        </div>
    );
}
