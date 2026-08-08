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
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const avatarInput = ref(null);
const avatarUploading = ref(false);
const avatarError = ref('');
const mobileProfileMenuOpen = ref(false);
const passwordModalOpen = ref(false);
const currentPassword = ref('');
const newPassword = ref('');
const newPasswordConfirmation = ref('');
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showNewPasswordConfirmation = ref(false);
const passwordSaving = ref(false);
const passwordError = ref('');
const passwordMessage = ref('');
const notificationPermission = ref(webPushPermission());
const pushSubscriptionActive = ref(false);
const notificationMessage = ref('');
const unreadChatCount = ref(0);
const backendOrigin = api.defaults.baseURL.replace(/\/api\/v1\/?$/, '');
const seenNotificationIds = new Set();
let notificationTimer;
// The nutrition module is retained for a future release but is temporarily hidden from clients.
const nutritionFeatureEnabled = false;
const defaultAvatar = 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=120&q=80';
const mediaUrl = (path) => /^https?:\/\//i.test(path ?? '')
    ? path
    : `${backendOrigin}/${String(path ?? '').replace(/^\/+/, '')}`;
const avatarSource = computed(() => auth.user?.avatar_path ? mediaUrl(auth.user.avatar_path) : defaultAvatar);
const accessLabel = computed(() => auth.user?.access_status === 'paid' ? 'Платный доступ' : 'Бесплатный доступ');
const greetingName = computed(() => {
    if (auth.user?.first_name)
        return auth.user.first_name;
    return String(auth.user?.name ?? '').trim().split(/\s+/).filter(Boolean)[0] ?? 'Анастасия';
});
const navItems = computed(() => {
    if (auth.isTrainer) {
        return [
            { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
            { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
            { label: 'Главная', to: '/app', icon: 'home' },
            { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
            { label: 'Участницы', to: '/participants', icon: 'groups' },
            { label: 'Чат', to: '/chat', icon: 'forum', unread: unreadChatCount.value },
        ];
    }
    return [
        { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
        { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
        { label: 'Главная', to: '/app', icon: 'home' },
        { label: 'Питание', to: '/nutrition', icon: 'lunch_dining' },
        { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
        { label: 'Прогресс', to: '/progress', icon: 'assignment' },
        { label: 'Чат', to: '/chat', icon: 'forum', unread: unreadChatCount.value },
    ];
});
const visibleNavItems = computed(() => navItems.value.filter((item) => nutritionFeatureEnabled || item.to !== '/nutrition'));
const orderedNavItems = computed(() => [...visibleNavItems.value].sort((first, second) => {
    if (first.to === '/app')
        return -1;
    if (second.to === '/app')
        return 1;
    return 0;
}));
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
function openPasswordModal() {
    mobileProfileMenuOpen.value = false;
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirmation.value = '';
    passwordError.value = '';
    passwordMessage.value = '';
    showCurrentPassword.value = false;
    showNewPassword.value = false;
    showNewPasswordConfirmation.value = false;
    passwordModalOpen.value = true;
}
function closePasswordModal() {
    if (!passwordSaving.value)
        passwordModalOpen.value = false;
}
async function changePassword() {
    passwordError.value = '';
    passwordMessage.value = '';
    if (newPassword.value.length < 12) {
        passwordError.value = 'Новый пароль должен содержать не менее 12 символов.';
        return;
    }
    if (newPassword.value !== newPasswordConfirmation.value) {
        passwordError.value = 'Новые пароли не совпадают.';
        return;
    }
    passwordSaving.value = true;
    try {
        const { data } = await api.patch('/auth/password', {
            current_password: currentPassword.value,
            password: newPassword.value,
            password_confirmation: newPasswordConfirmation.value,
        });
        passwordMessage.value = data.message;
        currentPassword.value = '';
        newPassword.value = '';
        newPasswordConfirmation.value = '';
    }
    catch (requestError) {
        const validationErrors = requestError.response?.data?.errors;
        passwordError.value = (validationErrors ? Object.values(validationErrors).flat().find(Boolean) : null)
            ?? requestError.response?.data?.message
            ?? 'Не удалось изменить пароль.';
    }
    finally {
        passwordSaving.value = false;
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
        if (!isInitial && notificationPermission.value === 'granted' && !pushSubscriptionActive.value)
            await showBrowserNotification(item);
    }
}
async function refreshUnreadChatCount() {
    if (!auth.user)
        return;
    const { data } = await api.get('/chat/unread-count');
    unreadChatCount.value = Number(data.data?.count ?? 0);
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
onMounted(async () => {
    pushSubscriptionActive.value = await syncWebPushSubscription().catch(() => false);
    notificationPermission.value = webPushPermission();
    await Promise.all([
        checkBrowserNotifications(true).catch(() => undefined),
        refreshUnreadChatCount().catch(() => undefined),
    ]);
    notificationTimer = window.setInterval(() => {
        checkBrowserNotifications().catch(() => undefined);
        refreshUnreadChatCount().catch(() => undefined);
    }, 10000);
});
function closeMobileProfileMenuOnEscape(event) {
    if (event.key === 'Escape') {
        mobileProfileMenuOpen.value = false;
        closePasswordModal();
    }
}
onMounted(() => window.addEventListener('keydown', closeMobileProfileMenuOnEscape));
onBeforeUnmount(() => {
    window.clearInterval(notificationTimer);
    window.removeEventListener('keydown', closeMobileProfileMenuOnEscape);
});
</script>

<template>
  <RouterView v-if="route.name === 'login' || route.name === 'landing' || route.name === 'privacy-policy'" />

  <div v-else class="app-gradient min-h-screen text-on-surface">
    <aside class="glass-panel fixed bottom-6 left-6 top-6 z-40 hidden w-[236px] flex-col rounded-[24px] p-5 lg:flex">
      <RouterLink to="/" class="mb-9 flex h-[72px] items-center" aria-label="Новая Я">
        <img class="h-full w-full object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
      </RouterLink>

      <nav class="grid gap-2 overflow-y-auto pr-1">
        <RouterLink
          v-for="item in orderedNavItems"
          :key="item.to + item.label"
          :to="item.to"
          class="tap-clear relative flex h-12 items-center gap-3 rounded-2xl px-4 text-sm font-semibold text-on-muted transition hover:bg-white/5 hover:text-primary"
          active-class="bg-primary-container/30 text-primary shadow-[0_8px_26px_rgba(109,56,168,0.18)]"
        >
          <span class="material-symbols-outlined text-[22px]">{{ item.icon }}</span>
          {{ item.label }}
          <span v-if="item.unread" class="ml-auto grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-extrabold text-[#470382]" :aria-label="`${item.unread} непрочитанных сообщений`">{{ item.unread > 99 ? '99+' : item.unread }}</span>
        </RouterLink>
      </nav>

    </aside>

    <main class="mx-auto min-h-screen w-full max-w-[1280px] px-5 pb-28 pt-7 lg:pl-[292px] lg:pr-10">
      <header class="mb-8 flex items-center justify-between gap-4">
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
          <button class="relative grid h-11 w-11 place-items-center rounded-2xl border border-white/10 bg-surface-container text-on-muted" type="button" :title="pushSubscriptionActive ? 'Push-уведомления включены' : 'Включить push-уведомления'" aria-label="Включить push-уведомления" :aria-pressed="pushSubscriptionActive" @click="enableBrowserNotifications">
            <span class="material-symbols-outlined text-[22px]">notifications</span>
            <span v-if="!pushSubscriptionActive" class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary" />
          </button>
          <div class="relative h-11 w-11 overflow-hidden rounded-full border border-primary/30 bg-surface-high">
            <img
              class="h-full w-full object-cover"
              alt="Аватар пользователя"
              :src="avatarSource"
              @error="(event) => { event.currentTarget.src = defaultAvatar; }"
            />
            <button
              class="absolute inset-0 hidden place-items-center bg-black/35 text-white opacity-0 transition hover:opacity-100 focus:opacity-100 lg:grid"
              type="button"
              title="Открыть меню профиля"
              aria-label="Открыть меню профиля"
              :aria-expanded="mobileProfileMenuOpen"
              @click="mobileProfileMenuOpen = !mobileProfileMenuOpen"
            >
              <span class="material-symbols-outlined text-[18px]">manage_accounts</span>
            </button>
            <button class="absolute inset-0 grid place-items-center lg:hidden" type="button" aria-label="Открыть меню профиля" :aria-expanded="mobileProfileMenuOpen" @click="mobileProfileMenuOpen = !mobileProfileMenuOpen"><span class="sr-only">Меню профиля</span></button>
            <input ref="avatarInput" class="hidden" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" @change="uploadAvatar" />
          </div>
          <button v-if="mobileProfileMenuOpen" class="fixed inset-0 z-40 cursor-default" type="button" aria-label="Закрыть меню профиля" @click="mobileProfileMenuOpen = false" />
          <div v-if="mobileProfileMenuOpen" class="absolute right-0 top-14 z-50 w-60 overflow-hidden rounded-2xl border border-white/10 bg-surface-highest p-2 shadow-2xl" role="menu" aria-label="Меню профиля">
            <div class="mb-2 rounded-xl border border-white/10 bg-surface-container/70 px-3 py-3">
              <p class="text-[11px] font-medium uppercase tracking-wide text-outline">Профиль</p>
              <p class="mt-1 truncate text-sm font-bold text-on-surface">{{ auth.user?.name }}</p>
              <p class="mt-1 text-xs font-semibold text-primary">{{ accessLabel }}</p>
            </div>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-on-surface hover:bg-white/5" type="button" role="menuitem" :disabled="avatarUploading" @click="openAvatarPicker"><span class="material-symbols-outlined text-primary">photo_camera</span>{{ avatarUploading ? 'Загружаем...' : 'Сменить аватар' }}</button>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-on-surface hover:bg-white/5" type="button" role="menuitem" @click="openPasswordModal"><span class="material-symbols-outlined text-primary">password</span>Сменить пароль</button>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-red-200 hover:bg-red-500/10" type="button" role="menuitem" @click="logout"><span class="material-symbols-outlined">logout</span>Выйти из профиля</button>
          </div>
        </div>
        <p v-if="avatarError || notificationMessage" class="absolute right-5 top-20 z-50 max-w-72 rounded-xl border border-white/10 bg-surface-highest/95 px-3 py-2 text-xs font-semibold text-on-surface shadow-xl lg:right-10">{{ avatarError || notificationMessage }}</p>
      </header>

      <RouterView />

      <footer class="mt-10 border-t border-white/10 pt-5 text-center text-xs text-on-muted">
        <RouterLink to="/privacy-policy" class="underline decoration-primary/60 underline-offset-4 transition hover:text-primary">
          Политика конфиденциальности
        </RouterLink>
      </footer>
    </main>

    <nav class="fixed bottom-0 z-50 w-full rounded-t-[28px] border-t border-white/5 bg-surface-highest/90 px-4 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_rgba(109,56,168,0.15)] backdrop-blur-2xl lg:hidden">
      <div class="mx-auto flex h-20 max-w-md items-center justify-around overflow-x-auto">
        <RouterLink
          v-for="item in orderedNavItems"
          :key="item.to + item.label"
          :to="item.to"
          class="tap-clear relative flex w-16 flex-col items-center justify-center text-on-muted transition"
          active-class="scale-110 font-bold text-primary"
        >
          <span class="material-symbols-outlined mb-1 text-[24px]">{{ item.icon }}</span>
          <span class="text-[10px] font-semibold leading-[14px]">{{ item.label }}</span>
          <span v-if="item.unread" class="absolute right-1 top-2 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-extrabold text-[#470382]" :aria-label="`${item.unread} непрочитанных сообщений`">{{ item.unread > 99 ? '99+' : item.unread }}</span>
        </RouterLink>
      </div>
    </nav>

    <Teleport to="body">
      <div v-if="passwordModalOpen" class="app-modal-backdrop z-[100] bg-black/70 backdrop-blur-sm" @mousedown.self="closePasswordModal">
        <section class="app-modal-panel glass-panel w-full max-w-lg rounded-[28px] p-5 sm:p-7" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
          <header class="mb-6 flex items-start justify-between gap-4">
            <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Безопасность</p><h2 id="password-modal-title" class="mt-1 text-2xl font-extrabold">Смена пароля</h2></div>
            <button class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" @click="closePasswordModal"><span class="material-symbols-outlined">close</span></button>
          </header>
          <form class="grid gap-4" @submit.prevent="changePassword">
            <label class="grid gap-2 text-sm font-bold text-on-muted">Текущий пароль<span class="relative"><input v-model="currentPassword" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-4 pr-12 text-on-surface outline-none focus:border-primary/50" :type="showCurrentPassword ? 'text' : 'password'" autocomplete="current-password" required /><button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted hover:text-primary" type="button" :aria-label="showCurrentPassword ? 'Скрыть пароль' : 'Показать пароль'" @click="showCurrentPassword = !showCurrentPassword"><span class="material-symbols-outlined text-[20px]">{{ showCurrentPassword ? 'visibility_off' : 'visibility' }}</span></button></span></label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Новый пароль<span class="relative"><input v-model="newPassword" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-4 pr-12 text-on-surface outline-none focus:border-primary/50" :type="showNewPassword ? 'text' : 'password'" autocomplete="new-password" minlength="12" required /><button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted hover:text-primary" type="button" :aria-label="showNewPassword ? 'Скрыть пароль' : 'Показать пароль'" @click="showNewPassword = !showNewPassword"><span class="material-symbols-outlined text-[20px]">{{ showNewPassword ? 'visibility_off' : 'visibility' }}</span></button></span></label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Повторите новый пароль<span class="relative"><input v-model="newPasswordConfirmation" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-4 pr-12 text-on-surface outline-none focus:border-primary/50" :type="showNewPasswordConfirmation ? 'text' : 'password'" autocomplete="new-password" minlength="12" required /><button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted hover:text-primary" type="button" :aria-label="showNewPasswordConfirmation ? 'Скрыть пароль' : 'Показать пароль'" @click="showNewPasswordConfirmation = !showNewPasswordConfirmation"><span class="material-symbols-outlined text-[20px]">{{ showNewPasswordConfirmation ? 'visibility_off' : 'visibility' }}</span></button></span></label>
            <p class="text-xs leading-5 text-on-muted">Используйте не менее 12 символов. Остальные активные сеансы будут завершены.</p>
            <p v-if="passwordError" class="rounded-xl border border-danger/20 bg-danger-container/20 px-4 py-3 text-sm font-bold text-danger" role="alert">{{ passwordError }}</p>
            <p v-if="passwordMessage" class="rounded-xl border border-primary/25 bg-primary/10 px-4 py-3 text-sm font-bold text-primary" role="status">{{ passwordMessage }}</p>
            <button class="rounded-2xl bg-primary px-6 py-4 font-extrabold text-[#470382] disabled:opacity-50" type="submit" :disabled="passwordSaving">{{ passwordSaving ? 'Сохраняем...' : 'Изменить пароль' }}</button>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
