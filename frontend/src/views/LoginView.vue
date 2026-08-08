<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const mode = ref(route.query.mode === 'reset' && route.query.token ? 'reset' : 'login');
const firstName = ref('');
const lastName = ref('');
const email = ref(String(route.query.email ?? ''));
const phone = ref('');
const goal = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const resetToken = ref(String(route.query.token ?? ''));
const privacyAccepted = ref(false);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const submitting = ref(false);
const error = ref('');
const message = ref('');

const heading = computed(() => ({
    login: 'Войти в кабинет',
    register: 'Создать аккаунт',
    forgot: 'Сбросить пароль',
    reset: 'Новый пароль',
}[mode.value]));
const description = computed(() => ({
    login: 'Продолжите обучение и отчёты',
    register: 'Заполните данные для регистрации',
    forgot: 'Укажите email, использованный при регистрации',
    reset: 'Придумайте новый пароль для кабинета',
}[mode.value]));

function setMode(nextMode) {
    mode.value = nextMode;
    password.value = '';
    passwordConfirmation.value = '';
    showPassword.value = false;
    showPasswordConfirmation.value = false;
    error.value = '';
    message.value = '';
}

function getRequestError(requestError) {
    const validationErrors = requestError.response?.data?.errors;
    return (validationErrors ? Object.values(validationErrors).flat().find(Boolean) : null)
        ?? requestError.response?.data?.message;
}

async function submit() {
    error.value = '';
    message.value = '';
    if (['register', 'reset'].includes(mode.value) && password.value.length < 12) {
        error.value = 'Пароль должен содержать не менее 12 символов.';
        return;
    }
    if (mode.value === 'reset' && password.value !== passwordConfirmation.value) {
        error.value = 'Пароли не совпадают.';
        return;
    }
    if (mode.value === 'register' && !privacyAccepted.value) {
        error.value = 'Для регистрации необходимо согласиться с Политикой конфиденциальности.';
        return;
    }

    submitting.value = true;
    try {
        if (mode.value === 'login') {
            await auth.login(email.value, password.value);
            await router.push('/app');
        }
        else if (mode.value === 'register') {
            await auth.register({
                first_name: firstName.value,
                last_name: lastName.value,
                email: email.value,
                phone: phone.value,
                goal: goal.value,
                password: password.value,
                privacy_policy_accepted: privacyAccepted.value,
            });
            await router.push('/app');
        }
        else if (mode.value === 'forgot') {
            const { data } = await api.post('/auth/forgot-password', { email: email.value });
            message.value = data.message;
        }
        else {
            const { data } = await api.post('/auth/reset-password', {
                email: email.value,
                token: resetToken.value,
                password: password.value,
                password_confirmation: passwordConfirmation.value,
            });
            message.value = data.message;
            window.history.replaceState({}, '', '/login');
            setTimeout(() => setMode('login'), 1200);
        }
    }
    catch (requestError) {
        error.value = getRequestError(requestError) ?? 'Не удалось выполнить запрос. Попробуйте ещё раз.';
    }
    finally {
        submitting.value = false;
    }
}
</script>

<template>
  <main class="app-gradient min-h-screen overflow-x-hidden px-4 py-4 text-on-surface sm:px-5 sm:py-8 lg:px-10">
    <header class="mx-auto hidden max-w-7xl items-center lg:flex">
      <RouterLink to="/" class="flex h-12 items-center" aria-label="Новая Я">
        <img class="h-full w-[164px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, курс Лазаревой" />
      </RouterLink>
    </header>

    <section class="mx-auto grid min-h-[calc(100dvh-2rem)] max-w-7xl items-center gap-12 py-4 sm:min-h-[calc(100dvh-4rem)] lg:min-h-[calc(100vh-88px)] lg:grid-cols-[1.1fr_0.9fr] lg:py-14">
      <div class="hidden max-w-2xl lg:block">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-surface-high px-4 py-2">
          <span class="material-symbols-outlined text-[18px] text-primary" style="font-variation-settings: 'FILL' 1">star</span>
          <span class="text-xs font-semibold text-on-muted">Онлайн-программа трансформации</span>
        </div>
        <h1 class="text-glow mt-7 text-[42px] font-extrabold leading-[48px] tracking-tight text-on-surface lg:text-[64px] lg:leading-[72px]">Твоя новая версия начинается <span class="text-primary">сегодня</span></h1>
        <p class="mt-6 max-w-xl text-lg leading-8 text-on-muted">Питание, тренировки, отчёты, прогресс и поддержка команды в одном личном кабинете.</p>
      </div>

      <div class="glass-panel relative w-full max-w-xl justify-self-center rounded-[28px] p-5 lg:max-w-none lg:p-7">
        <div class="mb-6 flex items-center justify-between gap-4">
          <div class="min-w-0">
            <h2 class="text-2xl font-extrabold">{{ heading }}</h2>
            <p class="mt-1 text-sm text-on-muted">{{ description }}</p>
          </div>
          <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-primary/15 text-primary"><span class="material-symbols-outlined">{{ mode === 'register' ? 'person_add' : 'lock' }}</span></div>
        </div>

        <form class="grid gap-4" @submit.prevent="submit">
          <div v-if="mode === 'login' || mode === 'register'" class="grid grid-cols-2 gap-2 rounded-2xl bg-surface-low p-1">
            <button type="button" class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition" :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'login' }" @click="setMode('login')">Вход</button>
            <button type="button" class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition" :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'register' }" @click="setMode('register')">Регистрация</button>
          </div>

          <div v-if="mode === 'register'" class="grid gap-4 sm:grid-cols-2">
            <label class="grid gap-2 text-sm font-bold text-on-muted">Имя<input v-model.trim="firstName" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50" type="text" autocomplete="given-name" placeholder="Мария" required /></label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Фамилия<input v-model.trim="lastName" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50" type="text" autocomplete="family-name" placeholder="Иванова" required /></label>
          </div>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">Номер телефона<input v-model.trim="phone" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 999 000-00-00" required /></label>
          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">Цель<textarea v-model.trim="goal" class="min-h-24 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50" placeholder="Что вы хотите получить от курса?" required /></label>

          <label class="grid gap-2 text-sm font-bold text-on-muted">Email<input v-model.trim="email" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50" type="email" inputmode="email" autocomplete="email" placeholder="post@mail.ru" required /></label>

          <label v-if="mode !== 'forgot'" class="grid gap-2 text-sm font-bold text-on-muted">
            Пароль
            <span class="relative">
              <input v-model="password" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-4 pr-12 text-base text-on-surface outline-none focus:border-primary/50" :type="showPassword ? 'text' : 'password'" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" :placeholder="mode === 'login' ? 'Введите пароль' : 'Не менее 12 символов'" minlength="12" required />
              <button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted hover:text-primary" type="button" :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'" @click="showPassword = !showPassword"><span class="material-symbols-outlined text-[20px]">{{ showPassword ? 'visibility_off' : 'visibility' }}</span></button>
            </span>
          </label>

          <label v-if="mode === 'reset'" class="grid gap-2 text-sm font-bold text-on-muted">
            Повторите пароль
            <span class="relative">
              <input v-model="passwordConfirmation" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-4 pr-12 text-base text-on-surface outline-none focus:border-primary/50" :type="showPasswordConfirmation ? 'text' : 'password'" autocomplete="new-password" placeholder="Повторите новый пароль" minlength="12" required />
              <button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted hover:text-primary" type="button" :aria-label="showPasswordConfirmation ? 'Скрыть пароль' : 'Показать пароль'" @click="showPasswordConfirmation = !showPasswordConfirmation"><span class="material-symbols-outlined text-[20px]">{{ showPasswordConfirmation ? 'visibility_off' : 'visibility' }}</span></button>
            </span>
          </label>

          <button v-if="mode === 'login'" class="justify-self-start text-sm font-bold text-primary hover:underline" type="button" @click="setMode('forgot')">Забыли пароль?</button>

          <label v-if="mode === 'register'" class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-sm leading-5 text-on-muted">
            <input v-model="privacyAccepted" class="mt-0.5 h-4 w-4 shrink-0 accent-primary" type="checkbox" required />
            <span>Я согласен(на) с <RouterLink to="/privacy-policy" target="_blank" class="font-bold text-primary underline underline-offset-2">Политикой конфиденциальности</RouterLink>.</span>
          </label>

          <p v-if="error" class="rounded-xl border border-danger/20 bg-danger-container/20 px-4 py-3 text-sm font-bold text-danger" role="alert">{{ error }}</p>
          <p v-if="message" class="rounded-xl border border-primary/25 bg-primary/10 px-4 py-3 text-sm font-bold text-primary" role="status">{{ message }}</p>

          <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white shadow-[0_8px_32px_rgba(109,56,168,0.3)] disabled:cursor-wait disabled:opacity-60" type="submit" :disabled="submitting || auth.loading">{{ submitting || auth.loading ? 'Проверяем...' : (mode === 'forgot' ? 'Отправить ссылку' : mode === 'reset' ? 'Сохранить пароль' : 'Продолжить') }}</button>
          <button v-if="mode === 'forgot' || mode === 'reset'" class="rounded-2xl border border-white/10 px-6 py-3 text-sm font-bold text-on-muted" type="button" @click="setMode('login')">Вернуться ко входу</button>
        </form>
      </div>
    </section>
  </main>
</template>
