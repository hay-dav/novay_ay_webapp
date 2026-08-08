const DEFAULT_NOTIFICATION_URL = '/app';
const NOTIFICATION_ICON = '/public-image/favicon.png?v=2';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data?.json() ?? {};
    }
    catch {
        payload = { body: event.data?.text() ?? '' };
    }

    event.waitUntil(self.registration.showNotification(payload.title ?? 'Новая Я', {
        body: payload.body ?? '',
        icon: payload.icon ?? NOTIFICATION_ICON,
        badge: payload.badge ?? NOTIFICATION_ICON,
        tag: payload.tag,
        data: {
            url: payload.url ?? DEFAULT_NOTIFICATION_URL,
        },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const requestedDestination = new URL(
        event.notification.data?.url ?? DEFAULT_NOTIFICATION_URL,
        self.location.origin,
    );
    const destination = requestedDestination.origin === self.location.origin
        ? requestedDestination.href
        : new URL(DEFAULT_NOTIFICATION_URL, self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true,
        });

        for (const client of windows) {
            if ('navigate' in client)
                await client.navigate(destination);
            return client.focus();
        }

        return self.clients.openWindow(destination);
    })());
});
