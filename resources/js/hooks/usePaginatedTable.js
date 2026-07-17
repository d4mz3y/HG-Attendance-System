import { useState, useEffect, useCallback } from 'react';
import api from '../api';

export function usePaginatedTable(initialPath, initialFilters = {}) {
    const [path, setPath] = useState(initialPath);
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
    const [filters, setFilters] = useState(initialFilters);
    const [loading, setLoading] = useState(false);

    const load = useCallback((page = 1) => {
        setLoading(true);
        const params = { page, ...filters };
        Object.keys(params).forEach((key) => {
            if (params[key] === '' || params[key] === null || params[key] === undefined) {
                delete params[key];
            }
        });
        api.get(path, { params })
            .then((r) => {
                setRows(r.data.data);
                setMeta({
                    current_page: r.data.current_page,
                    last_page: r.data.last_page,
                });
            })
            .finally(() => setLoading(false));
    }, [path, filters]);

    useEffect(() => {
        load(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [path, JSON.stringify(filters)]);

    const updateFilter = (name, value) => {
        setFilters((f) => ({ ...f, [name]: value }));
    };

    const changePath = (newPath) => {
        setPath(newPath);
    };

    return { rows, meta, filters, loading, load, updateFilter, changePath };
}
