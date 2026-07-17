export default function Paginator({ meta, onPage }) {
    if (meta.last_page <= 1) return null;

    return (
        <div className="flex items-center justify-between text-sm text-slate-600">
            <span>
                Page {meta.current_page} of {meta.last_page}
            </span>
            <div className="flex gap-2">
                <button
                    type="button"
                    disabled={meta.current_page <= 1}
                    className="rounded-lg border px-3 py-1 disabled:opacity-40"
                    onClick={() => onPage(meta.current_page - 1)}
                >
                    Previous
                </button>
                <button
                    type="button"
                    disabled={meta.current_page >= meta.last_page}
                    className="rounded-lg border px-3 py-1 disabled:opacity-40"
                    onClick={() => onPage(meta.current_page + 1)}
                >
                    Next
                </button>
            </div>
        </div>
    );
}
