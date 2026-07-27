<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
const router = useRouter();
const auth = useAuthStore();
const email = ref('');
const password = ref('password');
const mode = ref('login');
const name = ref('');
const phone = ref('');
const goal = ref('');
const privacyAccepted = ref(true);

const error = ref('');
async function submit() {
    error.value = '';
    try {
        if (mode.value === 'login') {
            await auth.login(email.value, password.value);
        }
        else {
            if (!privacyAccepted.value) {
                error.value = 'Для регистрации необходимо согласиться с Политикой конфиденциальности.';
                return;
            }
            await auth.register({
                name: name.value,
                email: email.value,
                phone: phone.value,
                goal: goal.value,
                password: password.value,
                role: 'client',
                privacy_policy_accepted: privacyAccepted.value,
            });
        }
        await router.push('/app');
    }
    catch {
        error.value = 'Не удалось войти. Проверьте email и пароль.';
    }
}
</script>

<template>
  <main class="app-gradient min-h-screen overflow-hidden px-5 py-8 text-on-surface lg:px-10">
    <header class="mx-auto flex max-w-7xl items-center justify-between">
      <RouterLink to="/" class="flex h-12 items-center" aria-label="Новая Я">
        <img class="h-full w-[164px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
      </RouterLink>
      <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-2.5 text-sm font-extrabold text-white">
        Начать
      </button>
    </header>

    <section class="mx-auto grid min-h-[calc(100vh-88px)] max-w-7xl items-center gap-12 py-14 lg:grid-cols-[1.1fr_0.9fr]">
      <div class="max-w-2xl">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-surface-high px-4 py-2">
          <span class="material-symbols-outlined text-[18px] text-primary" style="font-variation-settings: 'FILL' 1">star</span>
          <span class="text-xs font-semibold text-on-muted">Онлайн программа трансформации</span>
        </div>

        <h1 class="text-glow mt-7 text-[42px] font-extrabold leading-[48px] tracking-tight text-on-surface lg:text-[64px] lg:leading-[72px]">
          Твоя новая версия начинается <span class="text-primary">сегодня</span>
        </h1>
        <p class="mt-6 max-w-xl text-lg leading-8 text-on-muted">
          Комплексный подход к питанию, тренировкам и мышлению в одном приложении: видео, рацион, отчеты, прогресс и поддержка тренера.
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

      <div class="glass-panel relative rounded-[28px] p-5 lg:p-7">
        <div class="mb-6 flex items-center justify-between">
          <div>
            <h2 class="text-2xl font-extrabold">Войти в кабинет</h2>
            <p class="mt-1 text-sm text-on-muted">Продолжите обучение и отчеты</p>
          </div>
          <div class="grid h-12 w-12 place-items-center rounded-full bg-primary/15 text-primary">
            <span class="material-symbols-outlined">lock_open</span>
          </div>
        </div>

        <form class="grid gap-4" @submit.prevent="submit">
          <div class="grid grid-cols-2 gap-2 rounded-2xl bg-surface-low p-1">
            <button
              type="button"
              class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition"
              :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'login' }"
              @click="mode = 'login'"
            >
              Вход
            </button>
            <button
              type="button"
              class="rounded-xl px-4 py-3 text-sm font-bold text-on-muted transition"
              :class="{ 'bg-surface-high text-primary shadow-lg': mode === 'register' }"
              @click="mode = 'register'"
            >
              Регистрация
            </button>
          </div>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            ФИО
            <input v-model="name" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="text" placeholder="Иванова Мария Ивановна" required />
          </label>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            Номер телефона
            <input v-model="phone" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="tel" inputmode="tel" placeholder="+7 999 000-00-00" required />
          </label>

          <label v-if="mode === 'register'" class="grid gap-2 text-sm font-bold text-on-muted">
            Цель
            <textarea v-model.trim="goal" class="min-h-24 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" placeholder="Напишите что вы хотите получить от курса" required />
          </label>

          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Email
            <input v-model="email" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="email" placeholder="post@mail.ru" required />
          </label>

          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Пароль
            <input
              v-model="password"
              class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50"
              type="password"
              minlength="8"
              required
            />
          </label>

          <label v-if="mode === 'register'" class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-sm leading-5 text-on-muted">
            <input v-model="privacyAccepted" class="mt-0.5 h-4 w-4 shrink-0 accent-primary" type="checkbox" required />
            <span>
              Я ознакомлен(а) и согласен(на) с
              <RouterLink to="/privacy-policy" target="_blank" class="font-bold text-primary underline underline-offset-2">Политикой конфиденциальности</RouterLink>.
            </span>
          </label>

          <p v-if="error" class="rounded-xl border border-danger/20 bg-danger-container/20 px-4 py-3 text-sm font-bold text-danger">
            {{ error }}
          </p>
          <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white shadow-[0_8px_32px_rgba(109,56,168,0.3)]" type="submit">
            {{ auth.loading ? 'Проверяем...' : 'Продолжить' }}
          </button>
        </form>
      </div>
    </section>
  </main>
</template>
