<script setup>
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import StatTile from '@/components/StatTile.vue';
const stats = ref({ users: 0, paid_users: 0, pending_access: 0, courses: 0, clients: [] });
const requests = ref([]);
const notice = ref({ title: 'Напоминание о замерах', body: 'Сегодня день контрольных замеров и фото прогресса.', recipient: 'all' });
async function load() {
    const [{ data: dashboard }, { data: access }] = await Promise.all([
        api.get('/admin/dashboard'),
        api.get('/access-requests').catch(() => ({ data: { data: [] } })),
    ]);
    stats.value = dashboard.data;
    requests.value = access.data;
}
async function setAccess(client, access_status) {
    const { data } = await api.patch(`/admin/users/${client.id}`, { access_status });
    Object.assign(client, data.data);
}
async function approve(request, status) {
    const { data } = await api.patch(`/access-requests/${request.id}/approve`, { status });
    Object.assign(request, data.data);
    await load();
}
async function sendNotice() {
    await api.post('/admin/notifications', { ...notice.value, save_template: true });
    notice.value.body = '';
}
onMounted(load);
</script>

<template>
  <section class="grid gap-5 lg:grid-cols-4">
    <StatTile label="Пользователей" :value="String(stats.users)" icon="groups" />
    <StatTile label="Оплачено" :value="String(stats.paid_users)" icon="verified" />
    <StatTile label="Заявки" :value="String(stats.pending_access)" icon="fact_check" tone="danger" />
    <StatTile label="Курсы" :value="String(stats.courses)" icon="video_library" />

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-3">
      <div class="mb-5 flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">доступы</p>
          <h2 class="mt-1 text-2xl font-extrabold">Клиенты</h2>
        </div>
        <span class="material-symbols-outlined text-primary">manage_accounts</span>
      </div>
      <div class="grid gap-3">
        <div v-for="client in stats.clients" :key="client.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <strong class="block">{{ client.name }}</strong>
              <span class="text-sm text-on-muted">{{ client.email }} · {{ client.phone ?? 'телефон не указан' }}</span>
              <p class="mt-1 text-xs font-bold text-primary">{{ client.group_name ?? 'Без группы' }}</p>
            </div>
            <div class="flex gap-2">
              <button class="rounded-xl px-4 py-2 text-sm font-extrabold" :class="client.access_status === 'paid' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(client, 'paid')">
                Платный
              </button>
              <button class="rounded-xl px-4 py-2 text-sm font-extrabold" :class="client.access_status === 'free' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(client, 'free')">
                Бесплатный
              </button>
            </div>
          </div>
        </div>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5">
      <h2 class="text-2xl font-extrabold">Рассылка</h2>
      <form class="mt-5 grid gap-3" @submit.prevent="sendNotice">
        <input v-model="notice.title" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" placeholder="Заголовок" />
        <textarea v-model="notice.body" class="min-h-32 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" placeholder="Текст" />
        <select v-model="notice.recipient" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none">
          <option value="all">Все</option>
          <option value="paid">Платный доступ</option>
          <option value="free">Бесплатный доступ</option>
        </select>
        <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white" type="submit">
          Отправить
        </button>
      </form>
    </article>

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-4">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-2xl font-extrabold">Заявки на доступ</h2>
        <span class="material-symbols-outlined text-primary">assignment_turned_in</span>
      </div>
      <div class="grid gap-3 lg:grid-cols-2">
        <div v-for="request in requests" :key="request.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <strong class="block">{{ request.user?.name ?? 'Клиент' }}</strong>
              <span class="text-sm text-on-muted">{{ request.questionnaire }}</span>
            </div>
            <span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">{{ request.status }}</span>
          </div>
          <div v-if="request.status === 'pending'" class="mt-4 flex gap-2">
            <button class="rounded-xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]" @click="approve(request, 'approved')">Одобрить</button>
            <button class="rounded-xl border border-white/10 px-4 py-2 text-sm font-extrabold text-on-muted" @click="approve(request, 'rejected')">Отклонить</button>
          </div>
        </div>
      </div>
    </article>
  </section>
</template>
