import axios from 'axios';
import { clearAuthToken, readAuthToken } from '@/services/authStorage';

function getApiBaseUrl() {
    const configuredUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1';
    const browserHost = window.location.hostname;

    // `localhost` on a phone is the phone itself. During local development,
    // reuse the host that served the frontend so one build works on desktop
    // and on devices connected to the same network.
    return configuredUrl.replace(/^(https?):\/\/(?:localhost|127\.0\.0\.1)(?=[:/]|$)/, (_, protocol) => `${protocol}://${browserHost}`);
}

export const api = axios.create({
    baseURL: getApiBaseUrl(),
    headers: {
        Accept: 'application/json',
    },
});
api.interceptors.request.use((config) => {
    const token = readAuthToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});
api.interceptors.response.use((response) => response, (error) => {
    // Do not log a user out because a public endpoint or a background request
    // returned 401. The authenticated /auth/me check is the sole authority.
    if (error.response?.status === 401 && error.config?.url?.endsWith('/auth/me')) {
        clearAuthToken();
        if (window.location.pathname !== '/login') {
            window.location.assign('/login');
        }
    }
    return Promise.reject(error);
});
