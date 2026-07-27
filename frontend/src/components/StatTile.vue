<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    icon: { type: String, default: 'monitoring' },
    tone: { type: String, default: 'primary' },
});
</script>

<template>
  <article class="glass-panel relative overflow-hidden rounded-[24px] p-5">
    <div class="absolute -right-8 -top-8 h-24 w-24 rounded-full blur-2xl" :class="tone === 'danger' ? 'bg-danger/10' : 'bg-primary/10'" />
    <div class="relative flex items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-on-muted">{{ label }}</p>
        <strong class="mt-3 block text-[30px] font-extrabold leading-none text-on-surface">{{ value }}</strong>
      </div>
      <div class="grid h-11 w-11 place-items-center rounded-2xl bg-primary/15 text-primary">
        <span class="material-symbols-outlined text-[22px]">{{ icon ?? 'monitoring' }}</span>
      </div>
    </div>
  </article>
</template>
