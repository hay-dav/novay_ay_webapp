import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './assets/styles/main.css';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}

const app = createApp(App);

app.use(createPinia());
app.use(router);

// Wait for the first navigation (including auth checks and redirects) before
// mounting the UI. This prevents AppShell from flashing before LandingView.
router.isReady().then(() => app.mount('#app'));
