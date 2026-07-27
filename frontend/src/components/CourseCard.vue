<script setup>
defineProps({
    course: { type: Object, required: true },
    index: { type: Number, required: true },
});
const formatter = new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    maximumFractionDigits: 0,
});
</script>

<template>
  <article class="group overflow-hidden rounded-[24px] border border-white/10 bg-surface-container transition hover:-translate-y-1 hover:border-primary/30 hover:shadow-[0_16px_44px_rgba(109,56,168,0.22)]">
    <div class="relative aspect-video overflow-hidden bg-surface-high">
      <img
        class="h-full w-full object-cover opacity-80 transition duration-500 group-hover:scale-105"
        :alt="course.title"
        :src="
          index === 1
            ? 'https://images.unsplash.com/photo-1518611012118-696072aa579a?auto=format&fit=crop&w=900&q=80'
            : index === 2
              ? 'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?auto=format&fit=crop&w=900&q=80'
              : 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=900&q=80'
        "
      />
      <div class="absolute inset-0 bg-gradient-to-t from-surface-lowest via-transparent to-transparent" />
      <button class="absolute left-1/2 top-1/2 grid h-14 w-14 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-white/15 backdrop-blur-xl">
        <span class="material-symbols-outlined text-primary">play_arrow</span>
      </button>
      <span class="absolute left-4 top-4 rounded-full bg-primary-container/80 px-3 py-1 text-xs font-bold text-white">
        {{ course.level }}
      </span>
    </div>

    <div class="p-5">
      <h3 class="text-lg font-extrabold leading-6 text-on-surface">{{ course.title }}</h3>
      <p class="mt-2 line-clamp-2 text-sm leading-6 text-on-muted">{{ course.description }}</p>
      <div class="mt-5 flex items-center justify-between gap-4">
        <strong class="text-primary">{{ formatter.format(course.price_cents / 100) }}</strong>
        <RouterLink :to="`/courses/${course.slug}`" class="rounded-xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]">
          Открыть
        </RouterLink>
      </div>
    </div>
  </article>
</template>
