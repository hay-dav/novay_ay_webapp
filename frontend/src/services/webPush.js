import { api } from '@/services/api';

export function isWebPushSupported() {
    return typeof window !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;
}

export function webPushPermission() {
    return isWebPushSupported() ? Notification.permission : 'unsupported';
}

export async function enableWebPush() {
    if (!isWebPushSupported())
        throw new Error('unsupported');

    const permission = await Notification.requestPermission();
    if (permission !== 'granted')
        return { permission, active: false };

    const registration = await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        const { data } = await api.get('/push-subscriptions/public-key');
        if (!data.enabled || !data.public_key)
            throw new Error('not-configured');

        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(data.public_key),
        });
    }

    await saveSubscription(subscription);
    return { permission, active: true };
}

export async function syncWebPushSubscription() {
    if (!isWebPushSupported() || Notification.permission !== 'granted')
        return false;

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription)
        return false;

    await saveSubscription(subscription);
    return true;
}

export async function disableWebPush() {
    if (!isWebPushSupported())
        return;

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription)
        return;

    await api.delete('/push-subscriptions', {
        data: { endpoint: subscription.endpoint },
    }).catch(() => undefined);
    await subscription.unsubscribe();
}

async function saveSubscription(subscription) {
    const serialized = subscription.toJSON();
    await api.post('/push-subscriptions', {
        endpoint: serialized.endpoint,
        keys: serialized.keys,
        content_encoding: window.PushManager.supportedContentEncodings?.[0] ?? 'aes128gcm',
    });
}

function urlBase64ToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
}
