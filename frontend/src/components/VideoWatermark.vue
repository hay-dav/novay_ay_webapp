<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

const participantName = computed(() => String(auth.user?.name ?? '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .join(' '));

const visible = computed(() => auth.user?.role === 'client');
</script>

<template>
  <span v-if="visible" class="video-watermark" aria-hidden="true">
    Курс Лазаревой<template v-if="participantName"> · {{ participantName }}</template>
  </span>
</template>
