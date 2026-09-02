<script setup>
defineProps({
    label: { type: String, default: 'Загружаем материалы…' },
});
</script>

<template>
  <div class="glass-panel grid min-h-64 place-items-center rounded-[28px] p-8 text-center" role="status" aria-live="polite">
    <div>
      <span class="material-symbols-outlined animate-pulse text-[52px] text-primary">progress_activity</span>
      <p class="mt-4 text-sm font-bold text-on-muted">{{ label }}</p>
    </div>
  </div>
</template>
