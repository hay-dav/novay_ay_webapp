import { defineStore } from 'pinia';
import { api } from '@/services/api';

const TOKEN_STORAGE_KEY = 'novaya_ya_token';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        token: sessionStorage.getItem(TOKEN_STORAGE_KEY),
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
                sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
            }
            finally {
                this.loading = false;
            }
        },
        async register(payload) {
            const { data } = await api.post('/auth/register', payload);
            this.token = data.token;
            this.user = data.user;
            sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
        },
        async fetchMe() {
            if (!this.token)
                return;
            const { data } = await api.get('/auth/me');
            this.user = data.user;
        },
        async logout() {
            await api.post('/auth/logout').catch(() => undefined);
            this.user = null;
            this.token = null;
            sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        },
    },
});
