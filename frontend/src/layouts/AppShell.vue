<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
import {
    disableWebPush,
    enableWebPush,
    syncWebPushSubscription,
    webPushPermission,
} from '@/services/webPush';
import { createRealtimeClient } from '@/services/realtime';
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const avatarInput = ref(null);
const avatarUploading = ref(false);
const avatarError = ref('');
const mobileProfileMenuOpen = ref(false);
const mobileNavigationOpen = ref(false);
const profileModalOpen = ref(false);
const profileSaving = ref(false);
const profileError = ref('');
const profileForm = ref({ first_name: '', last_name: '', phone: '', new_password: '', new_password_confirmation: '' });
const notificationPermission = ref(webPushPermission());
const pushSubscriptionActive = ref(false);
const notificationMessage = ref('');
const unreadChatCount = ref(0);
const chatPushPreferences = ref({});
const backendOrigin = api.defaults.baseURL.replace(/\/api\/v1\/?$/, '');
const seenNotificationIds = new Set();
const failedAvatarUrls = new Set();
let realtime;
let profileRefreshTimer;
const defaultAvatar = '/public-image/default-avatar-v1.svg';
const mediaUrl = (path) => /^https?:\/\//i.test(path ?? '')
    ? path
    : `${backendOrigin}/${String(path ?? '').replace(/^\/+/, '')}`;
const avatarSource = computed(() => auth.user?.avatar_path ? mediaUrl(auth.user.avatar_path) : defaultAvatar);
const canEditProfile = computed(() => auth.user?.role === 'client'
    || auth.user?.role === 'admin'
    || Number(auth.user?.id) === 2);
async function refreshAvatarUrl(event) {
    const failedUrl = event.currentTarget.currentSrc;
    if (!failedUrl || failedAvatarUrls.has(failedUrl)) {
        event.currentTarget.src = defaultAvatar;
        return;
    }

    failedAvatarUrls.add(failedUrl);
    await auth.fetchMe().catch(() => undefined);
    // A failed refresh must not leave a broken image or cause an error loop.
    if (avatarSource.value === failedUrl)
        event.currentTarget.src = defaultAvatar;
}
const accessLabel = computed(() => auth.user?.access_status === 'paid' ? 'Платный доступ' : 'Бесплатный доступ');
// Existing profiles store the given name first. Keep the greeting aligned
// with the previously established account display behaviour.
const greetingName = computed(() => {
    const parts = String(auth.user?.name ?? '').trim().split(/\s+/).filter(Boolean);
    // Legacy administrator profile contains only the surname. Keep the header
    // personal even until that profile is edited through the account settings.
    if (parts.length === 1 && parts[0].toLocaleLowerCase('ru-RU') === 'лазарева')
        return 'Анастасия';
    return parts[0] ?? 'Анастасия';
});
const navItems = computed(() => {
    if (auth.isStaff) {
        return [
            { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
            { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
            { label: 'Главная', to: '/app', icon: 'home' },
            { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
            { label: 'Эфиры с экспертами', to: '/expert-lives', icon: 'live_tv' },
            { label: 'Рецепты', to: '/recipes', icon: 'restaurant' },
            { label: 'База знаний', to: '/knowledge-base', icon: 'library_books' },
        { label: 'Новости', to: '/news', icon: 'campaign' },
            { label: 'Участницы', to: '/participants', icon: 'groups' },
            ...(['admin', 'curator'].includes(auth.user?.role ?? '') ? [{ label: 'Доступы', to: '/access-management', icon: 'key' }] : []),
            { label: 'Чаты', to: '/chat', icon: 'forum', unread: unreadChatCount.value },
        ];
    }
    return [
        { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
        { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
        { label: 'Главная', to: '/app', icon: 'home' },
        { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
        { label: 'Эфиры с экспертами', to: '/expert-lives', icon: 'live_tv' },
        { label: 'Рецепты', to: '/recipes', icon: 'restaurant' },
        { label: 'База знаний', to: '/knowledge-base', icon: 'library_books' },
        { label: 'Новости', to: '/news', icon: 'campaign' },
        { label: 'Избранное', to: '/favorites', icon: 'favorite' },
        { label: 'Прогресс', to: '/progress', icon: 'assignment' },
        { label: 'Тарифы', to: '/tariffs', icon: 'sell' },
        { label: 'Чаты', to: '/chat', icon: 'forum', unread: unreadChatCount.value },
    ];
});
const visibleNavItems = computed(() => navItems.value);
const orderedNavItems = computed(() => [...visibleNavItems.value].sort((first, second) => {
    if (first.to === '/app')
        return -1;
    if (second.to === '/app')
        return 1;
    return 0;
}));
const mobileMenuItems = computed(() => {
    const items = visibleNavItems.value.filter((item) => !['/app', '/chat', '/favorites'].includes(item.to));
    return ['/lessons', '/podcasts'].map((path) => items.find((item) => item.to === path)).filter(Boolean)
        .concat(items.filter((item) => !['/lessons', '/podcasts'].includes(item.to)));
});
function openChatDirectory() {
    mobileNavigationOpen.value = false;
    // When the user is already inside a conversation, the route does not
    // change. Notify ChatView explicitly so the chat icon still opens its list.
    window.dispatchEvent(new Event('novaya-ya:show-chat-list'));
}
async function logout() {
    mobileProfileMenuOpen.value = false;
    await disableWebPush().catch(() => undefined);
    await auth.logout();
    await router.push('/login');
}
function chooseAvatar() {
    avatarInput.value?.click();
}
function openAvatarPicker() {
    mobileProfileMenuOpen.value = false;
    chooseAvatar();
}
function openProfileEditor() {
    mobileProfileMenuOpen.value = false;
    const [firstName = '', ...lastName] = String(auth.user?.name ?? '').trim().split(/\s+/).filter(Boolean);
    profileForm.value = { first_name: firstName, last_name: lastName.join(' '), phone: auth.user?.phone ?? '', new_password: '', new_password_confirmation: '' };
    profileError.value = '';
    profileModalOpen.value = true;
}
async function saveProfile() {
    profileSaving.value = true;
    profileError.value = '';
    try {
        const { data } = await api.patch('/auth/profile', profileForm.value);
        auth.user = data.data;
        profileModalOpen.value = false;
    }
    catch (error) {
        profileError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? 'Не удалось сохранить данные.';
    }
    finally {
        profileSaving.value = false;
    }
}
async function uploadAvatar(event) {
    const [file] = event.target.files ?? [];
    if (!file)
        return;

    avatarError.value = '';
    avatarUploading.value = true;
    try {
        const formData = new FormData();
        formData.append('avatar', file);
        const { data } = await api.post('/auth/avatar', formData);
        auth.user = data.data;
    }
    catch (error) {
        avatarError.value = error.response?.data?.errors?.avatar?.[0] ?? 'Не удалось загрузить аватар.';
    }
    finally {
        avatarUploading.value = false;
        event.target.value = '';
    }
}
async function checkBrowserNotifications(isInitial = false) {
    if (!auth.user)
        return;

    const { data } = await api.get('/notifications');
    for (const item of data.data ?? []) {
        if (seenNotificationIds.has(item.id))
            continue;
        seenNotificationIds.add(item.id);
        if (!isInitial && notificationPermission.value === 'granted' && !pushSubscriptionActive.value && (item.type !== 'chat' || isChatPushEnabled(item)))
            await showBrowserNotification(item);
    }
}
async function refreshUnreadChatCount() {
    if (!auth.user)
        return;
    const { data } = await api.get('/chat/unread-count');
    unreadChatCount.value = Number(data.data?.count ?? 0);
}
async function loadChatPushPreferences() {
    if (!auth.user)
        return;
    const { data } = await api.get('/chat/notification-preferences');
    chatPushPreferences.value = data.data ?? {};
}
function isChatPushEnabled(notification) {
    const key = notification.data?.chat_notification_key;
    return !key || chatPushPreferences.value[key] !== false;
}
async function handleRealtimeNotification(notification) {
    if (!notification || seenNotificationIds.has(notification.id))
        return;
    seenNotificationIds.add(notification.id);
    // Let the open chat fetch the message immediately. The unread-count API
    // request can complete independently without delaying the conversation.
    window.dispatchEvent(new CustomEvent('novaya-ya:notification', { detail: notification }));
    if (notification.type === 'chat')
        await refreshUnreadChatCount().catch(() => undefined);
    if (notificationPermission.value === 'granted' && !pushSubscriptionActive.value && (notification.type !== 'chat' || isChatPushEnabled(notification)))
        await showBrowserNotification(notification);
}
function startRealtime() {
    if (!auth.token || !auth.user)
        return;
    realtime = createRealtimeClient(auth.token);
    realtime.private(`users.${auth.user.id}`).listen('.notification.created', ({ notification }) => {
        handleRealtimeNotification(notification).catch(() => undefined);
    });
}
async function showBrowserNotification(item) {
    const options = {
        body: item.body,
        icon: '/public-image/favicon.png?v=2',
        badge: '/public-image/favicon.png?v=2',
        tag: `novaya-ya-${item.id}`,
        data: {
            url: item.data?.link_url ?? '/app',
        },
    };

    if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.ready;
        await registration.showNotification(item.title, options);
        return;
    }

    const notification = new Notification(item.title, options);
    notification.onclick = () => window.focus();
}
async function enableBrowserNotifications() {
    notificationMessage.value = '';
    if (notificationPermission.value === 'unsupported') {
        notificationMessage.value = 'На iPhone сначала добавьте сайт на экран «Домой», затем откройте его с нового значка.';
        return;
    }

    try {
        const result = await enableWebPush();
        notificationPermission.value = result.permission;
        pushSubscriptionActive.value = result.active;
        notificationMessage.value = result.active
            ? 'Push-уведомления включены.'
            : 'Разрешите уведомления в настройках браузера или телефона.';
        await checkBrowserNotifications(true);
    }
    catch {
        notificationMessage.value = 'Не удалось включить push-уведомления. Обновите страницу и попробуйте ещё раз.';
    }
}
async function toggleBrowserNotifications() {
    if (!pushSubscriptionActive.value) {
        await enableBrowserNotifications();
        return;
    }

    notificationMessage.value = '';
    try {
        await disableWebPush();
        pushSubscriptionActive.value = false;
        notificationMessage.value = 'Push-уведомления отключены.';
    }
    catch {
        notificationMessage.value = 'Не удалось отключить push-уведомления. Попробуйте ещё раз.';
    }
}
onMounted(async () => {
    pushSubscriptionActive.value = await syncWebPushSubscription().catch(() => false);
    notificationPermission.value = webPushPermission();
    await Promise.all([
        checkBrowserNotifications(true).catch(() => undefined),
        refreshUnreadChatCount().catch(() => undefined),
        loadChatPushPreferences().catch(() => undefined),
    ]);
    startRealtime();
    // Private S3 avatar URLs are short-lived. Refresh the profile before the
    // one-hour signature expires, including during a long open mobile session.
    profileRefreshTimer = window.setInterval(() => auth.fetchMe().catch(() => undefined), 45 * 60 * 1000);
});
function closeMobileProfileMenuOnEscape(event) {
    if (event.key === 'Escape')
        mobileProfileMenuOpen.value = false;
}
function updateChatPushPreference(event) {
    const preference = event.detail;
    if (preference?.chat_key) chatPushPreferences.value = { ...chatPushPreferences.value, [preference.chat_key]: preference.enabled };
}
function refreshUnreadAfterChatRead() {
    refreshUnreadChatCount().catch(() => undefined);
}
onMounted(() => window.addEventListener('keydown', closeMobileProfileMenuOnEscape));
onMounted(() => window.addEventListener('novaya-ya:chat-push-preference', updateChatPushPreference));
onMounted(() => window.addEventListener('novaya-ya:chat-read', refreshUnreadAfterChatRead));
onBeforeUnmount(() => {
    realtime?.disconnect();
    window.clearInterval(profileRefreshTimer);
    window.removeEventListener('keydown', closeMobileProfileMenuOnEscape);
    window.removeEventListener('novaya-ya:chat-push-preference', updateChatPushPreference);
    window.removeEventListener('novaya-ya:chat-read', refreshUnreadAfterChatRead);
});
</script>

<template>
  <RouterView v-if="route.name === 'login' || route.name === 'landing' || route.name === 'privacy-policy'" />

  <div v-else class="app-gradient min-h-screen overflow-x-hidden text-on-surface">
    <aside class="glass-panel fixed bottom-6 left-6 top-6 z-40 hidden w-[236px] flex-col rounded-[24px] p-5 lg:flex">
      <RouterLink to="/" class="mb-9 flex h-[72px] items-center" aria-label="Новая Я">
        <img class="h-full w-full object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
      </RouterLink>

      <nav class="desktop-sidebar-scroll grid gap-2 overflow-y-auto pr-2">
        <RouterLink
          v-for="item in orderedNavItems"
          :key="item.to + item.label"
          :to="item.to"
          class="tap-clear relative flex h-12 items-center gap-3 rounded-2xl px-4 text-sm font-semibold text-on-muted transition hover:bg-white/5 hover:text-primary"
          active-class="bg-primary-container/30 text-primary shadow-[0_8px_26px_rgba(109,56,168,0.18)]"
          @click="item.to === '/chat' && openChatDirectory()"
        >
          <span class="material-symbols-outlined text-[22px]">{{ item.icon }}</span>
          {{ item.label }}
          <span v-if="item.unread" class="ml-auto grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-extrabold text-[#470382]" :aria-label="`${item.unread} непрочитанных сообщений`">{{ item.unread > 99 ? '99+' : item.unread }}</span>
        </RouterLink>
      </nav>

      <div class="mt-auto rounded-2xl border border-white/10 bg-surface-container/70 p-4">
        <p class="text-xs font-medium uppercase text-outline">Профиль</p>
        <p class="mt-1 truncate text-sm font-bold text-on-surface">{{ auth.user?.name }}</p>
        <p class="mt-1 text-xs font-semibold text-primary">
          {{ accessLabel }}
        </p>
        <button class="mt-4 w-full rounded-xl border border-white/10 px-4 py-2 text-sm font-bold text-on-muted" @click="logout">
          Выйти
        </button>
      </div>
    </aside>

    <main class="mx-auto min-h-screen min-w-0 w-full max-w-[1280px] px-5 pb-28 pt-7 lg:pl-[292px] lg:pr-10" :class="route.name === 'chat' ? 'max-lg:px-0 max-lg:pb-20 max-lg:pt-0' : ''">
      <header class="mb-8 flex items-center justify-between gap-4" :class="route.name === 'chat' ? 'max-lg:hidden' : ''">
        <div>
          <RouterLink to="/" class="mb-2 flex h-9 items-center lg:hidden" aria-label="Новая Я">
            <img class="h-full w-[128px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
          </RouterLink>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">личный кабинет</p>
          <h1 class="mt-1 text-[28px] font-extrabold leading-9 text-on-surface lg:text-[36px] lg:leading-[44px]">
            Привет, {{ greetingName }}
          </h1>
        </div>

        <div class="relative flex items-center gap-3">
          <button class="relative grid h-11 w-11 place-items-center rounded-2xl border border-white/10 bg-surface-container text-on-muted" type="button" :title="pushSubscriptionActive ? 'Отключить push-уведомления' : 'Включить push-уведомления'" :aria-label="pushSubscriptionActive ? 'Отключить push-уведомления' : 'Включить push-уведомления'" :aria-pressed="pushSubscriptionActive" @click="toggleBrowserNotifications">
            <span class="material-symbols-outlined text-[22px]">{{ pushSubscriptionActive ? 'notifications' : 'notifications_off' }}</span>
            <span v-if="!pushSubscriptionActive" class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary" />
          </button>
          <div class="relative h-11 w-11 overflow-hidden rounded-full border border-primary/30 bg-surface-high">
            <img
              class="h-full w-full object-cover"
              alt="Аватар пользователя"
              :src="avatarSource"
              @error="refreshAvatarUrl"
            />
            <button
              v-if="canEditProfile"
              class="absolute inset-0 hidden place-items-center bg-black/55 text-white opacity-0 transition hover:opacity-100 focus:opacity-100 lg:grid"
              type="button"
              title="Изменить аватар"
              aria-label="Изменить аватар"
              :disabled="avatarUploading"
              @click="openProfileEditor"
            >
              <span class="material-symbols-outlined text-[18px]">photo_camera</span>
            </button>
            <button class="absolute inset-0 grid place-items-center lg:hidden" type="button" aria-label="Открыть меню профиля" :aria-expanded="mobileProfileMenuOpen" @click="mobileProfileMenuOpen = !mobileProfileMenuOpen"><span class="sr-only">Меню профиля</span></button>
            <input ref="avatarInput" class="hidden" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" @change="uploadAvatar" />
          </div>
          <button v-if="mobileProfileMenuOpen" class="fixed inset-0 z-40 cursor-default lg:hidden" type="button" aria-label="Закрыть меню профиля" @click="mobileProfileMenuOpen = false" />
          <div v-if="mobileProfileMenuOpen" class="absolute right-0 top-14 z-50 w-60 overflow-hidden rounded-2xl border border-white/10 bg-surface-highest p-2 shadow-2xl lg:hidden" role="menu" aria-label="Меню профиля">
            <div class="mb-2 rounded-xl border border-white/10 bg-surface-container/70 px-3 py-3">
              <p class="text-[11px] font-medium uppercase tracking-wide text-outline">Профиль</p>
              <p class="mt-1 truncate text-sm font-bold text-on-surface">{{ auth.user?.name }}</p>
              <p class="mt-1 text-xs font-semibold text-primary">{{ accessLabel }}</p>
            </div>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-on-surface hover:bg-white/5" type="button" role="menuitem" :disabled="avatarUploading" @click="openAvatarPicker"><span class="material-symbols-outlined text-primary">photo_camera</span>{{ avatarUploading ? 'Загружаем...' : 'Сменить аватар' }}</button>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-on-surface hover:bg-white/5" type="button" role="menuitem" @click="openProfileEditor"><span class="material-symbols-outlined text-primary">manage_accounts</span>Редактировать данные</button>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-red-200 hover:bg-red-500/10" type="button" role="menuitem" @click="logout"><span class="material-symbols-outlined">logout</span>Выйти из профиля</button>
          </div>
        </div>
        <p v-if="avatarError || notificationMessage" class="absolute right-5 top-20 z-50 max-w-72 rounded-xl border border-white/10 bg-surface-highest/95 px-3 py-2 text-xs font-semibold text-on-surface shadow-xl lg:right-10">{{ avatarError || notificationMessage }}</p>
      </header>

      <RouterView />

      <footer class="mt-10 border-t border-white/10 pt-5 text-center text-xs text-on-muted" :class="route.name === 'chat' ? 'max-lg:hidden' : ''">
        <RouterLink to="/privacy-policy" class="underline decoration-primary/60 underline-offset-4 transition hover:text-primary">
          Политика конфиденциальности
        </RouterLink>
      </footer>

      <div v-if="profileModalOpen" class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/70 px-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-[calc(5.75rem+env(safe-area-inset-bottom))] backdrop-blur-sm sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Редактирование профиля" @click.self="profileModalOpen = false">
        <form class="my-auto flex w-full max-w-md max-h-[calc(100dvh-6.5rem-env(safe-area-inset-bottom))] flex-col overflow-hidden rounded-[24px] border border-white/10 bg-surface-highest p-4 shadow-2xl sm:max-h-[calc(100dvh-2rem)] sm:rounded-[28px] sm:p-7" @submit.prevent="saveProfile">
          <div class="mb-3 flex shrink-0 items-center justify-between gap-3 sm:mb-5"><div><h2 class="text-lg font-extrabold sm:text-xl">Редактировать данные</h2><p class="mt-1 text-sm text-on-muted">Персональные данные и безопасность</p></div><button class="grid h-9 w-9 place-items-center rounded-xl text-on-muted hover:bg-white/5 sm:h-10 sm:w-10" type="button" aria-label="Закрыть" @click="profileModalOpen = false"><span class="material-symbols-outlined">close</span></button></div>
          <div class="brand-scrollbar min-h-0 flex-1 overflow-y-auto px-0.5 pr-1">
            <button class="mb-3 flex items-center gap-3 rounded-2xl border border-white/10 bg-surface-container px-4 py-2.5 text-left text-sm font-bold hover:bg-white/5 sm:mb-5 sm:py-3" type="button" :disabled="avatarUploading" @click="chooseAvatar"><span class="material-symbols-outlined text-primary">photo_camera</span>{{ avatarUploading ? 'Загружаем...' : 'Сменить аватар' }}</button>
            <div class="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4"><label class="grid min-w-0 gap-1.5 text-sm font-semibold">Имя<input v-model.trim="profileForm.first_name" class="w-full min-w-0 rounded-xl border border-white/10 bg-surface-container px-3 py-2.5 text-on-surface outline-none focus:border-primary sm:py-3" required maxlength="100" /></label><label class="grid min-w-0 gap-1.5 text-sm font-semibold">Фамилия<input v-model.trim="profileForm.last_name" class="w-full min-w-0 rounded-xl border border-white/10 bg-surface-container px-3 py-2.5 text-on-surface outline-none focus:border-primary sm:py-3" maxlength="100" /></label></div>
            <label class="mt-3 grid gap-1.5 text-sm font-semibold sm:mt-4">Номер телефона<input v-model.trim="profileForm.phone" class="rounded-xl border border-white/10 bg-surface-container px-3 py-2.5 text-on-surface outline-none focus:border-primary sm:py-3" type="tel" maxlength="32" /></label>
            <div class="mt-4 border-t border-white/10 pt-4 sm:mt-6 sm:pt-5"><h3 class="font-extrabold">Смена пароля</h3><p class="mt-1 text-xs text-on-muted">Введите новый пароль и повторите его. Действующий пароль не требуется.</p><div class="mt-3 grid gap-3"><input v-model="profileForm.new_password" class="rounded-xl border border-white/10 bg-surface-container px-3 py-2.5 text-on-surface outline-none focus:border-primary sm:py-3" type="password" placeholder="Новый пароль (не менее 12 символов)" autocomplete="new-password" /><input v-model="profileForm.new_password_confirmation" class="rounded-xl border border-white/10 bg-surface-container px-3 py-2.5 text-on-surface outline-none focus:border-primary sm:py-3" type="password" placeholder="Повторите новый пароль" autocomplete="new-password" /></div></div>
            <p v-if="profileError" class="mt-4 text-sm font-semibold text-red-300">{{ profileError }}</p>
          </div>
          <div class="mt-3 shrink-0 border-t border-white/10 pt-3"><button class="w-full rounded-xl bg-primary px-4 py-2.5 font-extrabold text-[#470382] disabled:opacity-60 sm:py-3" type="submit" :disabled="profileSaving">{{ profileSaving ? 'Сохраняем...' : 'Сохранить изменения' }}</button></div>
        </form>
      </div>
    </main>

    <div v-if="mobileNavigationOpen" class="mobile-navigation-backdrop fixed inset-0 z-[60] flex items-end bg-black/70 px-4 pt-16 backdrop-blur-sm lg:hidden" @click.self="mobileNavigationOpen = false">
      <div class="relative mx-auto w-full max-w-md">
        <section class="mobile-navigation-sheet brand-scrollbar relative z-10 w-full overflow-y-auto rounded-[28px] border border-white/10 bg-surface-highest p-4 shadow-2xl" role="dialog" aria-modal="true" aria-label="Разделы приложения">
          <div class="mb-3 flex items-center justify-between px-2"><h2 class="text-xl font-extrabold">Меню</h2><button class="grid h-10 w-10 place-items-center rounded-xl text-on-muted hover:bg-white/5" type="button" aria-label="Закрыть меню" @click="mobileNavigationOpen = false"><span class="material-symbols-outlined">close</span></button></div>
          <div class="grid grid-cols-2 gap-3">
            <RouterLink v-for="item in mobileMenuItems" :key="item.to" :to="item.to" class="flex min-h-24 flex-col justify-between rounded-2xl border border-white/10 bg-surface-container p-4 text-on-surface transition hover:border-primary/35 hover:bg-primary/10" :class="{ 'live-accent-tile': ['/lessons', '/podcasts'].includes(item.to), 'knowledge-accent-tile': item.to === '/knowledge-base' }" active-class="border-primary/50 bg-primary/15 text-primary" @click="mobileNavigationOpen = false">
              <span class="material-symbols-outlined text-[28px] text-primary">{{ item.icon }}</span><span class="text-sm font-extrabold leading-5">{{ item.label }}</span>
            </RouterLink>
            <RouterLink v-if="!auth.isStaff" to="/favorites" class="live-accent-tile flex min-h-24 flex-col justify-between rounded-2xl border border-white/10 p-4" @click="mobileNavigationOpen = false"><span class="material-symbols-outlined text-[28px]">favorite</span><span class="text-sm font-extrabold leading-5">Избранное</span></RouterLink>
          </div>
        </section>
        <span class="pointer-events-none absolute -bottom-3 left-1/2 z-20 h-7 w-7 -translate-x-1/2 rotate-45 border-b border-r border-white/10 bg-surface-highest shadow-[8px_8px_18px_rgba(109,56,168,0.18)]" aria-hidden="true" />
      </div>
    </div>

    <nav class="fixed bottom-0 z-[70] w-full rounded-t-[28px] border-t border-white/5 bg-surface-highest/90 px-4 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_rgba(109,56,168,0.15)] backdrop-blur-2xl lg:hidden" aria-label="Основная навигация">
      <div class="mx-auto grid h-20 max-w-md grid-cols-3 items-center">
        <RouterLink to="/app" class="tap-clear flex flex-col items-center justify-center text-on-muted transition" active-class="font-bold text-primary" @click="mobileNavigationOpen = false"><span class="material-symbols-outlined mb-1 text-[24px]">home</span><span class="text-[10px] font-semibold">Главная</span></RouterLink>
        <button class="tap-clear flex flex-col items-center justify-center rounded-2xl text-primary transition hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :class="mobileNavigationOpen ? 'bg-primary/15 font-bold text-primary' : ''" type="button" :aria-expanded="mobileNavigationOpen" @click="mobileNavigationOpen = !mobileNavigationOpen"><span class="material-symbols-outlined leading-none text-primary" style="font-size: 35px">apps</span><span class="text-[10px] font-semibold">Меню</span></button>
        <RouterLink to="/chat" class="tap-clear relative flex flex-col items-center justify-center text-on-muted transition" active-class="font-bold text-primary" @click="openChatDirectory"><span class="material-symbols-outlined mb-1 text-[24px]">forum</span><span class="text-[10px] font-semibold">Чаты</span><span v-if="unreadChatCount" class="absolute right-[26%] top-2 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-extrabold text-[#470382]">{{ unreadChatCount > 99 ? '99+' : unreadChatCount }}</span></RouterLink>
      </div>
    </nav>
  </div>
</template>
