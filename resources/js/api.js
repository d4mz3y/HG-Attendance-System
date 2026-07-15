import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
    },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('hg_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

api.interceptors.response.use(
    (r) => r,
    (err) => {
        if (err.response?.status === 401) {
            localStorage.removeItem('hg_token');
            if (!window.location.pathname.startsWith('/login') && window.location.pathname !== '/scan') {
                window.location.href = '/login';
            }
        }

        return Promise.reject(err);
    }
);

export default api;
