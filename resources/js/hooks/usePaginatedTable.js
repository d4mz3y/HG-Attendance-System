import { useState, useEffect, useCallback, useRef } from 'react';
import api from '../api';

export function usePaginatedTable(initialPath, initialFilters = {}) {
    const [path, setPath] = useState(initialPath);
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [filters, setFilters] = useState(initialFilters);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const requestId = useRef(0);

    const load = useCallback((page = 1) => {
        const currentRequest = ++requestId.current;
        setLoading(true);
        setError(null);
        const params = { page, ...filters };
        Object.keys(params).forEach((key) => {
            if (params[key] === '' || params[key] === null || params[key] === undefined) {
                delete params[key];
            }
        });
        return api.get(path, { params })
            .then((r) => {
                if (currentRequest !== requestId.current) return;
                setRows(r.data.data);
                setMeta({
                    current_page: r.data.current_page,
                    last_page: r.data.last_page,
                });
            })
            .catch((requestError) => {
                if (currentRequest === requestId.current) {
                    setError(requestError.response?.data?.message || 'Unable to load records.');
                }
                return undefined;
            })
            .finally(() => {
                if (currentRequest === requestId.current) setLoading(false);
            });
    }, [path, filters]);

    useEffect(() => {
        void load(1);
    }, [load]);

    const updateFilter = (name, value) => {
        setFilters((f) => ({ ...f, [name]: value }));
    };

    const changePath = (newPath) => {
        setPath(newPath);
    };

    return { rows, meta, filters, loading, error, load, updateFilter, changePath };
}
