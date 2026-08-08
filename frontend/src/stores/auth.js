import { defineStore } from 'pinia';
import { api } from '@/services/api';
import { clearAuthToken, readAuthToken, storeAuthToken } from '@/services/authStorage';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: readAuthToken(),
        loading: false,
    }),
    getters: {
        isAuthenticated: (state) => Boolean(state.token && state.user),
        isTrainer: (state) => ['curator', 'trainer', 'admin'].includes(state.user?.role ?? ''),
        isAdmin: (state) => ['trainer', 'admin'].includes(state.user?.role ?? ''),
    },
    actions: {
        async login(email, password) {
            this.loading = true;
            try {
                const { data } = await api.post('/auth/login', { email, password });
                this.token = data.token;
                this.user = data.user;
                storeAuthToken(data.token);
            }
            finally {
                this.loading = false;
            }
        },
        async register(payload) {
            const { data } = await api.post('/auth/register', payload);
            this.token = data.token;
            this.user = data.user;
            storeAuthToken(data.token);
        },
        async fetchMe() {
            if (!this.token)
                return;
            try {
                const { data } = await api.get('/auth/me');
                this.user = data.user;
            }
            catch (error) {
                // A lost mobile connection must not erase a valid long-lived
                // session. Only the server can confirm an invalid token.
                if (error.response?.status === 401)
                    this.clearSession();
                throw error;
            }
        },
        clearSession() {
            this.user = null;
            this.token = null;
            clearAuthToken();
        },
        async logout() {
            await api.post('/auth/logout').catch(() => undefined);
            this.clearSession();
        },
    },
});
