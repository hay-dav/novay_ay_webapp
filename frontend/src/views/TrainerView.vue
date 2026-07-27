<script setup>
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import StatTile from '@/components/StatTile.vue';
const dashboard = ref({
    clients: 0,
    revenue_cents: 0,
    active_courses: 0,
    pending_reviews: 0,
    clients_list: [],
});
onMounted(async () => {
    const { data } = await api.get('/trainer/dashboard');
    dashboard.value = data.data;
});
</script>

<template>
  <section class="grid gap-5 lg:grid-cols-4">
    <StatTile label="Клиентов" :value="String(dashboard.clients)" icon="groups" />
    <StatTile label="Доход" :value="`${Math.round(dashboard.revenue_cents / 100).toLocaleString('ru-RU')} ₽`" icon="payments" />
    <StatTile label="Курсов" :value="String(dashboard.active_courses)" icon="video_library" />
    <StatTile label="Проверки" :value="String(dashboard.pending_reviews)" icon="rate_review" tone="danger" />

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-2">
      <div class="mb-5 flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">сопровождение</p>
          <h2 class="mt-1 text-2xl font-extrabold">Клиенты</h2>
        </div>
        <button class="rounded-2xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]">Добавить</button>
      </div>
      <div class="grid gap-3">
        <article v-for="client in dashboard.clients_list" :key="client.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <div class="flex items-center gap-3">
            <div class="grid h-11 w-11 place-items-center rounded-full bg-primary/15 text-primary">
              <span class="material-symbols-outlined">person</span>
            </div>
            <div>
              <strong class="block">{{ client.name }}</strong>
              <span class="text-sm text-on-muted">{{ client.goal }}</span>
            </div>
          </div>
        </article>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-2">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-2xl font-extrabold">Очередь отчетов</h2>
        <span class="material-symbols-outlined text-primary">assignment</span>
      </div>
      <div class="grid gap-3">
        <div class="rounded-2xl border border-danger/20 bg-danger-container/20 p-4">
          <div class="flex items-center justify-between">
            <strong>Анна Смирнова</strong>
            <span class="text-xs font-bold uppercase text-danger">уточнить</span>
          </div>
          <p class="mt-2 text-sm leading-6 text-on-muted">Нет фото обеда, нужен комментарий тренера.</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <div class="flex items-center justify-between">
            <strong>Шеф НАСТЯ К.</strong>
            <span class="text-xs font-bold uppercase text-primary">проверить</span>
          </div>
          <p class="mt-2 text-sm leading-6 text-on-muted">Новый отчет по завтраку и воде.</p>
        </div>
      </div>
    </article>
  </section>
</template>
