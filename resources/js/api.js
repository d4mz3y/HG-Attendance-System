import axios from 'axios';
import { beginApiActivity, endApiActivity } from './loadingActivity';
import { clearAuthToken, getAuthToken } from './authToken';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
    },
});

api.interceptors.request.use((config) => {
    const token = getAuthToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return beginApiActivity(config);
}, (error) => {
    endApiActivity(error.config);
    return Promise.reject(error);
});

api.interceptors.response.use(
    (response) => {
        endApiActivity(response.config);
        return response;
    },
    (err) => {
        endApiActivity(err.config);
        if (err.response?.status === 401) {
            const usedUserToken = Boolean(err.config?.headers?.Authorization);
            const usedDeviceToken = Boolean(err.config?.headers?.['X-Device-Token']);
            if (usedUserToken && !usedDeviceToken) {
                clearAuthToken();
                window.dispatchEvent(new Event('hg:auth-expired'));
            }
            if (usedUserToken && !usedDeviceToken && !window.location.pathname.startsWith('/login') && window.location.pathname !== '/scan') {
                window.location.href = '/login';
            }
        }

        return Promise.reject(err);
    }
);

export default api;
