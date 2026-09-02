<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const email = ref(String(route.query.email ?? ''));
const password = ref('');
const mode = ref(route.query.mode === 'reset' && route.query.token ? 'reset' : 'login');
const name = ref('');
const phone = ref('');
const goal = ref('');
const privacyAccepted = ref(false);
const error = ref('');
const successMessage = ref('');
const passwordConfirmation = ref('');
const passwordVisible = ref(false);
const resetToken = computed(() => String(route.query.token ?? ''));

function setMode(nextMode) {
    if (mode.value === nextMode)
        return;

    mode.value = nextMode;
    password.value = '';
    passwordConfirmation.value = '';
    passwordVisible.value = false;
    error.value = '';
    successMessage.value = '';
}

function getRequestError(requestError) {
    const validationErrors = requestError.response?.data?.errors;
    const firstValidationError = validationErrors
        ? Object.values(validationErrors).flat().find(Boolean)
        : null;

    return firstValidationError ?? requestError.response?.data?.message;
}

async function submit() {
    error.value = '';
    successMessage.value = '';

    if (['register', 'reset'].includes(mode.value) && password.value.length < 12) {
        error.value = 'Пароль должен содержать не менее 12 символов.';
        return;
    }
    if (mode.value === 'register' && !privacyAccepted.value) {
        error.value = 'Для регистрации необходимо согласиться с Политикой конфиденциальности.';
        return;
    }

    try {
        if (mode.value === 'login') {
            await auth.login(email.value, password.value);
        }
        else if (mode.value === 'register') {
            await auth.register({
                name: name.value,
                email: email.value,
                phone: phone.value,
                goal: goal.value,
                password: password.value,
                privacy_policy_accepted: privacyAccepted.value,
            });
        }
        else if (mode.value === 'forgot') {
            const { data } = await api.post('/auth/forgot-password', { email: email.value });
            successMessage.value = data.message;
            return;
        }
        else {
            const { data } = await api.post('/auth/reset-password', {
                email: email.value,
                token: resetToken.value,
                password: password.value,
                password_confirmation: passwordConfirmation.value,
            });
            successMessage.value = data.message;
            password.value = '';
            passwordConfirmation.value = '';
            mode.value = 'login';
            await router.replace('/login');
            return;
        }
        await router.push('/app');
    }
    catch (requestError) {
        error.value = getRequestError(requestError)
            ?? (mode.value === 'register'
                ? 'Не удалось зарегистрироваться. Проверьте заполненные данные.'
                : mode.value === 'reset'
                    ? 'Не удалось изменить пароль. Проверьте ссылку и введённые данные.'
                    : 'Не удалось выполнить запрос. Проверьте введённые данные.');
    }
}
</script>

<template>
  <main class="app-gradient min-h-screen overflow-x-hidden px-4 py-4 text-on-surface sm:px-5 sm:py-8 lg:px-10">
    <header class="mx-auto hidden max-w-7xl items-center lg:flex">
      <RouterLink to="/" class="flex h-12 items-center" aria-label="Новая Я">
        <img
          class="h-full w-[164px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]"
          src="/public-image/novaya-ya-logo-header.png"
          alt="Новая Я, курс Лазаревой"
        />
      </RouterLink>
    </header>

    <section class="mx-auto grid min-h-[calc(100dvh-2rem)] max-w-7xl items-center gap-12 py-4 sm:min-h-[calc(100dvh-4rem)] lg:min-h-[calc(100vh-88px)] lg:grid-cols-[1.1fr_0.9fr] lg:py-14">
      <div class="hidden max-w-2xl lg:block">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-surface-high px-4 py-2">
          <span class="material-symbols-outlined text-[18px] text-primary" style="font-variation-settings: 'FILL' 1">star</span>
          <span class="text-xs font-semibold text-on-muted">Онлайн-программа трансформации</span>
        </div>

        <h1 class="text-glow mt-7 text-[42px] font-extrabold leading-[48px] tracking-tight text-on-surface lg:text-[64px] lg:leading-[72px]">
          Твоя новая версия начинается <span class="text-primary">сегодня</span>
        </h1>
        <p class="mt-6 max-w-xl text-lg leading-8 text-on-muted">
          Комплексный подход к питанию, тренировкам и мышлению в одном приложении: видео, рацион, отчёты, прогресс и поддержка тренера.
        </p>

        <div class="mt-10 grid grid-cols-3 gap-5 border-t border-white/10 pt-8">
          <div>
            <strong class="block text-2xl font-extrabold text-primary">4000+</strong>
            <span class="text-xs font-semibold text-on-muted">участниц</span>
          </div>
          <div>
            <strong class="block text-2xl font-extrabold text-primary">4</strong>
            <span class="text-xs font-semibold text-on-muted">недели</span>
          </div>
          <div>
            <strong class="block text-2xl font-extrabold text-primary">24/7</strong>
            <span class="text-xs font-semibold text-on-muted">поддержка</span>
          </div>
        </div>
      </div>

      <div class="glass-panel relative w-full max-w-xl justify-self-center rounded-[28px] p-5 lg:max-w-none lg:p-7">
        <div class="mb-6 flex items-center justify-between">
          <div>
            <h2 class="text-2xl font-extrabold">
              {{ mode === 'login' ? 'Войти в кабинет' : mode === 'register' ? 'Создать аккаунт' : mode === 'forgot' ? 'Восстановить пароль' : 'Новый пароль' }}
            </h2>
            <p class="mt-1 text-sm text-on-muted">
              {{ mode === 'login' ? 'Продолжите обучение и отчёты' : mode === 'register' ? 'Заполните данные для регистрации' : mode === 'forgot' ? 'Отправим ссылку для создания нового пароля' : 'Введите и подтвердите новый пароль' }}
            </p>
          </div>
          <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-primary/15 text-primary">
            <span class="material-symbols-outlined">{{ mode === 'login' ? 'lock_open' : 'person_add' }}</span>
          </div>
        </div>

        <form class="grid gap-4" @submit.prevent="submit">
          <div v-if="mode !== 'reset'" class="grid grid-cols-2 gap-2 rounded-2xl bg-surface-low p-1">
            <button
              type="button"
              class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition"
              :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'login' }"
              @click="setMode('login')"
            >
              Вход
            </button>
            <button
              type="button"
              class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition"
              :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'register' }"
              @click="setMode('register')"
            >
              Регистрация
            </button>
          </div>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            ФИО
            <input
              v-model.trim="name"
              class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50"
              type="text"
              autocomplete="name"
              placeholder="Иванова Мария Ивановна"
              required
            />
          </label>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            Номер телефона
            <input
              v-model.trim="phone"
              class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50"
              type="tel"
              inputmode="tel"
              autocomplete="tel"
              placeholder="+7 999 000-00-00"
              required
            />
          </label>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            Цель
            <textarea
              v-model.trim="goal"
              class="min-h-24 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50"
              placeholder="Напишите, что вы хотите получить от курса"
              required
            />
          </label>

          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Email
            <input
              v-model.trim="email"
              class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-base text-on-surface outline-none focus:border-primary/50"
              type="email"
              inputmode="email"
              autocomplete="email"
              placeholder="post@mail.ru"
              required
            />
          </label>

          <label v-if="mode !== 'forgot'" class="grid gap-2 text-sm font-bold text-on-muted">
            {{ mode === 'reset' ? 'Новый пароль' : 'Пароль' }}
            <span class="relative block">
              <input
                v-model="password"
                class="w-full rounded-2xl border border-white/10 bg-surface-low px-4 py-3 pr-12 text-base text-on-surface outline-none focus:border-primary/50"
                :type="passwordVisible ? 'text' : 'password'"
                :autocomplete="['register', 'reset'].includes(mode) ? 'new-password' : 'current-password'"
                :placeholder="['register', 'reset'].includes(mode) ? 'Не менее 12 символов' : 'Введите пароль'"
                minlength="12"
                required
              />
              <button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted transition hover:text-primary" type="button" :aria-label="passwordVisible ? 'Скрыть пароль' : 'Показать пароль'" :title="passwordVisible ? 'Скрыть пароль' : 'Показать пароль'" @click="passwordVisible = !passwordVisible">
                <span class="material-symbols-outlined text-[22px]">{{ passwordVisible ? 'visibility_off' : 'visibility' }}</span>
              </button>
            </span>
            <span v-if="['register', 'reset'].includes(mode)" class="text-xs font-medium leading-5 text-primary">
              Пароль должен содержать не менее 12 символов.
            </span>
          </label>

          <label v-if="mode === 'reset'" class="grid gap-2 text-sm font-bold text-on-muted">
            Повторите новый пароль
            <span class="relative block">
              <input v-model="passwordConfirmation" class="w-full rounded-2xl border border-white/10 bg-surface-low px-4 py-3 pr-12 text-base text-on-surface outline-none focus:border-primary/50" :type="passwordVisible ? 'text' : 'password'" autocomplete="new-password" placeholder="Повторите пароль" minlength="12" required />
              <button class="absolute inset-y-0 right-0 grid w-12 place-items-center text-on-muted transition hover:text-primary" type="button" :aria-label="passwordVisible ? 'Скрыть пароль' : 'Показать пароль'" :title="passwordVisible ? 'Скрыть пароль' : 'Показать пароль'" @click="passwordVisible = !passwordVisible">
                <span class="material-symbols-outlined text-[22px]">{{ passwordVisible ? 'visibility_off' : 'visibility' }}</span>
              </button>
            </span>
          </label>

          <button v-if="mode === 'login'" class="justify-self-start text-sm font-bold text-primary underline underline-offset-4" type="button" @click="setMode('forgot')">
            Забыли пароль?
          </button>

          <button v-if="mode === 'forgot'" class="justify-self-start text-sm font-bold text-primary underline underline-offset-4" type="button" @click="setMode('login')">
            Вернуться ко входу
          </button>

          <label v-if="mode === 'register'" class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-sm leading-5 text-on-muted">
            <input v-model="privacyAccepted" class="mt-0.5 h-4 w-4 shrink-0 accent-primary" type="checkbox" required />
            <span>
              Я ознакомлен(а) и согласен(на) с
              <RouterLink to="/privacy-policy" target="_blank" class="font-bold text-primary underline underline-offset-2">
                Политикой конфиденциальности
              </RouterLink>.
            </span>
          </label>

          <p v-if="error" class="rounded-xl border border-danger/20 bg-danger-container/20 px-4 py-3 text-sm font-bold text-danger" role="alert" aria-live="polite">
            {{ error }}
          </p>
          <p v-if="successMessage" class="rounded-xl border border-emerald-300/25 bg-emerald-500/10 px-4 py-3 text-sm font-bold text-emerald-100" role="status" aria-live="polite">
            {{ successMessage }}
          </p>

          <button
            class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white shadow-[0_8px_32px_rgba(109,56,168,0.3)] disabled:cursor-wait disabled:opacity-60"
            type="submit"
            :disabled="auth.loading"
          >
            {{ auth.loading ? 'Проверяем...' : mode === 'forgot' ? 'Отправить ссылку' : mode === 'reset' ? 'Сохранить новый пароль' : 'Продолжить' }}
          </button>
        </form>
      </div>
    </section>
  </main>
</template>
