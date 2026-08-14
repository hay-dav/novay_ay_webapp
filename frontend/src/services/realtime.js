import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;
export function createRealtimeClient(token) {
    const apiOrigin = new URL(import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080/api/v1').origin;
    const secure = window.location.protocol === 'https:';

    return new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_WS_KEY ?? 'local',
        // Pusher JS validates this field even when the connection is routed to
        // our self-hosted Soketi instance via wsHost.
        cluster: import.meta.env.VITE_WS_CLUSTER ?? 'mt1',
        wsHost: import.meta.env.VITE_WS_HOST ?? 'localhost',
        wsPort: Number(import.meta.env.VITE_WS_PORT ?? 6001),
        wssPort: Number(import.meta.env.VITE_WS_PORT ?? 443),
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: `${apiOrigin}/broadcasting/auth`,
        auth: {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        },
    });
}
