<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from '@/layouts/AppShell.vue';

const route = useRoute();
const cookieConsentVisible = ref(false);
const isGuestLive = computed(() => route.name === 'guest-live');

onMounted(() => {
  cookieConsentVisible.value = localStorage.getItem('novaya-ya-cookie-consent') !== 'accepted';
});

function acceptCookies() {
  localStorage.setItem('novaya-ya-cookie-consent', 'accepted');
  cookieConsentVisible.value = false;
}
</script>

<template>
  <RouterView v-if="isGuestLive" />
  <AppShell v-else />

  <div
    v-if="cookieConsentVisible"
    class="fixed inset-x-4 bottom-4 z-[100] mx-auto max-w-lg rounded-2xl border border-white/10 bg-surface-highest/95 p-4 shadow-2xl backdrop-blur-xl sm:bottom-6 sm:p-5"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cookie-consent-title"
    aria-describedby="cookie-consent-description"
  >
    <div class="flex gap-3">
      <span class="material-symbols-outlined mt-0.5 text-[26px] text-primary" aria-hidden="true">cookie</span>
      <div class="min-w-0 flex-1">
        <h2 id="cookie-consent-title" class="text-base font-extrabold text-on-surface">Мы используем cookies</h2>
        <p id="cookie-consent-description" class="mt-1 text-sm leading-5 text-on-muted">
          Cookies помогают сайту запоминать вход, сохранять настройки и улучшать работу сервиса.
        </p>
        <button class="mt-4 w-full rounded-xl bg-primary px-4 py-2.5 text-sm font-extrabold text-on-primary transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface-highest sm:w-auto" type="button" @click="acceptCookies">
          Понятно
        </button>
      </div>
    </div>
  </div>
</template>
