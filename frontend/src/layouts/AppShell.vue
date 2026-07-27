<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const avatarInput = ref(null);
const avatarUploading = ref(false);
const avatarError = ref('');
const mobileProfileMenuOpen = ref(false);
const notificationPermission = ref(typeof window !== 'undefined' && 'Notification' in window ? Notification.permission : 'unsupported');
const backendOrigin = api.defaults.baseURL.replace(/\/api\/v1\/?$/, '');
const seenNotificationIds = new Set();
let notificationTimer;
// The nutrition module is retained for a future release but is temporarily hidden from clients.
const nutritionFeatureEnabled = false;
const avatarSource = computed(() => auth.user?.avatar_path
    ? `${backendOrigin}/${auth.user.avatar_path}`
    : 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=120&q=80');
const accessLabel = computed(() => auth.user?.access_status === 'paid' ? 'Платный доступ' : 'Бесплатный доступ');
const navItems = computed(() => {
    if (auth.isTrainer) {
        return [
            { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
            { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
            { label: 'Главная', to: '/app', icon: 'home' },
            { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
            { label: 'Участницы', to: '/participants', icon: 'groups' },
            { label: 'Чат', to: '/chat', icon: 'forum' },
        ];
    }
    return [
        { label: 'Уроки', to: '/lessons', icon: 'menu_book' },
        { label: 'Подкасты', to: '/podcasts', icon: 'headphones' },
        { label: 'Главная', to: '/app', icon: 'home' },
        { label: 'Питание', to: '/nutrition', icon: 'lunch_dining' },
        { label: 'Тренировки', to: '/workouts', icon: 'exercise' },
        { label: 'Прогресс', to: '/progress', icon: 'assignment' },
        { label: 'Чат', to: '/chat', icon: 'forum' },
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
        if (!isInitial && notificationPermission.value === 'granted') {
            const notification = new Notification(item.title, {
                body: item.body,
                icon: '/public-image/favicon.png',
                tag: `novaya-ya-${item.id}`,
            });
            notification.onclick = () => window.focus();
        }
    }
}
async function enableBrowserNotifications() {
    if (notificationPermission.value === 'unsupported')
        return;
    notificationPermission.value = await Notification.requestPermission();
    await checkBrowserNotifications(true);
}
onMounted(async () => {
    await checkBrowserNotifications(true).catch(() => undefined);
    notificationTimer = window.setInterval(() => checkBrowserNotifications().catch(() => undefined), 10000);
});
function closeMobileProfileMenuOnEscape(event) {
    if (event.key === 'Escape')
        mobileProfileMenuOpen.value = false;
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
          class="tap-clear flex h-12 items-center gap-3 rounded-2xl px-4 text-sm font-semibold text-on-muted transition hover:bg-white/5 hover:text-primary"
          active-class="bg-primary-container/30 text-primary shadow-[0_8px_26px_rgba(109,56,168,0.18)]"
        >
          <span class="material-symbols-outlined text-[22px]">{{ item.icon }}</span>
          {{ item.label }}
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

    <main class="mx-auto min-h-screen w-full max-w-[1280px] px-5 pb-28 pt-7 lg:pl-[292px] lg:pr-10">
      <header class="mb-8 flex items-center justify-between gap-4">
        <div>
          <RouterLink to="/" class="mb-2 flex h-9 items-center lg:hidden" aria-label="Новая Я">
            <img class="h-full w-[128px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
          </RouterLink>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">личный кабинет</p>
          <h1 class="mt-1 text-[28px] font-extrabold leading-9 text-on-surface lg:text-[36px] lg:leading-[44px]">
            Привет, {{ auth.user?.name?.split(' ')[0] ?? 'Анастасия' }}
          </h1>
        </div>

        <div class="relative flex items-center gap-3">
          <button class="relative grid h-11 w-11 place-items-center rounded-2xl border border-white/10 bg-surface-container text-on-muted" type="button" title="Включить push-уведомления" aria-label="Включить push-уведомления" @click="enableBrowserNotifications">
            <span class="material-symbols-outlined text-[22px]">notifications</span>
            <span v-if="notificationPermission !== 'granted' && notificationPermission !== 'unsupported'" class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary" />
          </button>
          <div class="relative h-11 w-11 overflow-hidden rounded-full border border-primary/30 bg-surface-high">
            <img
              class="h-full w-full object-cover"
              alt="Аватар пользователя"
              :src="avatarSource"
            />
            <button
              v-if="auth.user?.role === 'client'"
              class="absolute inset-0 hidden place-items-center bg-black/55 text-white opacity-0 transition hover:opacity-100 focus:opacity-100 lg:grid"
              type="button"
              title="Изменить аватар"
              aria-label="Изменить аватар"
              :disabled="avatarUploading"
              @click="chooseAvatar"
            >
              <span class="material-symbols-outlined text-[18px]">photo_camera</span>
            </button>
            <button class="absolute inset-0 grid place-items-center lg:hidden" type="button" aria-label="Открыть меню профиля" :aria-expanded="mobileProfileMenuOpen" @click="mobileProfileMenuOpen = !mobileProfileMenuOpen"><span class="sr-only">Меню профиля</span></button>
            <input ref="avatarInput" class="hidden" type="file" accept="image/png,image/jpeg,image/webp" @change="uploadAvatar" />
          </div>
          <button v-if="mobileProfileMenuOpen" class="fixed inset-0 z-40 cursor-default lg:hidden" type="button" aria-label="Закрыть меню профиля" @click="mobileProfileMenuOpen = false" />
          <div v-if="mobileProfileMenuOpen" class="absolute right-0 top-14 z-50 w-60 overflow-hidden rounded-2xl border border-white/10 bg-surface-highest p-2 shadow-2xl lg:hidden" role="menu" aria-label="Меню профиля">
            <div class="mb-2 rounded-xl border border-white/10 bg-surface-container/70 px-3 py-3">
              <p class="text-[11px] font-medium uppercase tracking-wide text-outline">Профиль</p>
              <p class="mt-1 truncate text-sm font-bold text-on-surface">{{ auth.user?.name }}</p>
              <p class="mt-1 text-xs font-semibold text-primary">{{ accessLabel }}</p>
            </div>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-on-surface hover:bg-white/5" type="button" role="menuitem" :disabled="avatarUploading" @click="openAvatarPicker"><span class="material-symbols-outlined text-primary">photo_camera</span>{{ avatarUploading ? 'Загружаем...' : 'Сменить аватар' }}</button>
            <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-bold text-red-200 hover:bg-red-500/10" type="button" role="menuitem" @click="logout"><span class="material-symbols-outlined">logout</span>Выйти из профиля</button>
          </div>
        </div>
        <p v-if="avatarError" class="absolute right-5 top-20 max-w-64 rounded-xl border border-danger/20 bg-danger-container/90 px-3 py-2 text-xs font-semibold text-danger lg:right-10">{{ avatarError }}</p>
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
        </RouterLink>
      </div>
    </nav>
  </div>
</template>
