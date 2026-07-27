<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/services/api';
const backendOrigin = api.defaults.baseURL.replace(/\/api\/v1\/?$/, '');
const route = useRoute();
const participants = ref([]);
const loading = ref(false);
const detailsLoading = ref(false);
const selectedParticipant = ref(null);
const details = ref(null);
const commentBody = ref('');
const commentSaving = ref(false);
const today = new Date().toISOString().slice(0, 10);
const twoWeeksAgo = new Date(Date.now() - 13 * 86400000).toISOString().slice(0, 10);
const reportRange = ref({ from: twoWeeksAgo, to: today });
// Nutrition reports are retained in the codebase but temporarily disabled for staff.
const nutritionReportsEnabled = false;
const groupedFoodEntries = computed(() => {
    const groups = new Map();
    for (const entry of details.value?.food_entries ?? []) {
        const date = String(entry.eaten_on).slice(0, 10);
        groups.set(date, [...(groups.get(date) ?? []), entry]);
    }
    return [...groups.entries()];
});
async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/dashboard');
        participants.value = data.data.clients;
        const requestedClientId = Number(route.query.client);
        const requestedClient = participants.value.find((participant) => participant.id === requestedClientId);
        if (requestedClient)
            await openDetails(requestedClient);
    }
    finally {
        loading.value = false;
    }
}
async function setAccess(participant, access_status) {
    const { data } = await api.patch(`/admin/users/${participant.id}`, { access_status });
    Object.assign(participant, data.data);
}
async function openDetails(participant) {
    selectedParticipant.value = participant;
    await loadDetails();
}
async function loadDetails() {
    if (!selectedParticipant.value)
        return;
    detailsLoading.value = true;
    try {
        const { data } = await api.get(`/admin/users/${selectedParticipant.value.id}/details`, {
            params: reportRange.value,
        });
        details.value = data.data;
    }
    finally {
        detailsLoading.value = false;
    }
}
function closeDetails() {
    selectedParticipant.value = null;
    details.value = null;
    commentBody.value = '';
}
async function addComment() {
    if (!selectedParticipant.value || !commentBody.value.trim())
        return;
    commentSaving.value = true;
    try {
        const { data } = await api.post(`/admin/users/${selectedParticipant.value.id}/comments`, {
            body: commentBody.value.trim(),
        });
        details.value?.comments.unshift(data.data);
        commentBody.value = '';
    }
    finally {
        commentSaving.value = false;
    }
}
function formatDate(value) {
    return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(value));
}
function avatarUrl(path) {
    return path ? `${backendOrigin}/${path}` : '';
}
onMounted(load);
</script>

<template>
  <section class="grid gap-6">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Сопровождение</p>
      <h2 class="mt-2 text-[32px] font-extrabold leading-10">Участницы</h2>
    </div>

    <div v-if="loading" class="glass-panel rounded-[28px] p-5 text-sm text-on-muted">Загружаю участниц...</div>

    <div v-else class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="participant in participants" :key="participant.id" class="glass-panel rounded-[28px] p-5">
        <div class="flex items-start gap-4">
          <div v-if="participant.avatar_path" class="h-14 w-14 shrink-0 overflow-hidden rounded-full border border-primary/30 bg-surface-high">
            <img class="h-full w-full object-cover" :src="avatarUrl(participant.avatar_path)" :alt="`Аватар ${participant.name}`" />
          </div>
          <div v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary/15 text-primary">
            <span class="material-symbols-outlined">person</span>
          </div>
          <div class="min-w-0 flex-1">
            <h3 class="truncate text-xl font-extrabold">{{ participant.name }}</h3>
            <p class="mt-1 truncate text-sm text-on-muted">{{ participant.email }}</p>
            <p class="mt-1 truncate text-sm text-on-muted">{{ participant.phone ?? 'Телефон не указан' }}</p>
            <p class="mt-2 text-sm leading-6 text-on-muted">{{ participant.client_profile?.goal ?? 'Цель не указана' }}</p>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <div class="rounded-2xl border border-primary/15 bg-primary/5 p-3">
            <span class="block text-xs font-bold uppercase text-on-muted">Доступ</span>
            <strong class="mt-1 block text-sm" :class="participant.access_status === 'paid' ? 'text-primary' : 'text-on-muted'">{{ participant.access_status === 'paid' ? 'Платный' : 'Бесплатный' }}</strong>
          </div>
          <div class="rounded-2xl border border-white/10 bg-surface-container p-3">
            <span class="block text-xs font-bold uppercase text-on-muted">Тренировки</span>
            <strong class="mt-1 block text-sm text-on-surface">{{ participant.completed_workouts_count ?? 0 }} пройдено</strong>
          </div>
        </div>

        <div v-if="participant.latest_measurement" class="mt-3 rounded-2xl border border-primary/15 bg-primary/5 p-4">
          <div class="flex items-center justify-between gap-3">
            <span class="text-xs font-bold uppercase text-on-muted">Последний замер</span>
            <span class="text-xs font-semibold text-primary">{{ formatDate(participant.latest_measurement.measured_on) }}</span>
          </div>
          <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm font-bold">
            <span>Вес: {{ participant.latest_measurement.weight_kg }} кг</span>
            <span v-if="participant.latest_measurement.waist_cm != null">Талия: {{ participant.latest_measurement.waist_cm }} см</span>
          </div>
        </div>
        <p v-else class="mt-3 text-sm text-on-muted">Замеры пока не отправлены.</p>

        <button class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-primary/25 px-4 py-3 text-sm font-extrabold text-primary" type="button" @click="openDetails(participant)">
          <span class="material-symbols-outlined text-[20px]">assignment</span>
          Отчёты, замеры и комментарии
        </button>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <button class="rounded-xl px-4 py-2 text-sm font-extrabold" :class="participant.access_status === 'paid' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(participant, 'paid')">Доступ открыт</button>
          <button class="rounded-xl px-4 py-2 text-sm font-extrabold" :class="participant.access_status === 'free' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(participant, 'free')">Ограничить</button>
        </div>
      </article>
    </div>

    <Teleport to="body">
      <div v-if="selectedParticipant" class="fixed inset-0 z-[100] flex items-end justify-center bg-black/65 p-0 backdrop-blur-sm sm:items-center sm:p-5" @mousedown.self="closeDetails">
        <section class="glass-panel max-h-[94vh] w-full overflow-y-auto rounded-t-[28px] p-5 sm:max-w-4xl sm:rounded-[28px] sm:p-7" role="dialog" aria-modal="true" aria-label="Отчеты участницы">
          <header class="mb-6 flex items-start justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Участница</p>
              <h3 class="mt-1 text-2xl font-extrabold">{{ selectedParticipant.name }}</h3>
            </div>
            <button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" @click="closeDetails"><span class="material-symbols-outlined">close</span></button>
          </header>

          <div class="mb-6 flex flex-col gap-3 rounded-2xl bg-surface-container p-4 sm:flex-row sm:items-end">
            <label class="grid flex-1 gap-1 text-xs font-bold text-on-muted">С даты<input v-model="reportRange.from" class="rounded-xl border border-white/10 bg-surface-low px-3 py-2 text-on-surface" type="date" /></label>
            <label class="grid flex-1 gap-1 text-xs font-bold text-on-muted">По дату<input v-model="reportRange.to" class="rounded-xl border border-white/10 bg-surface-low px-3 py-2 text-on-surface" type="date" /></label>
            <button class="rounded-xl bg-primary px-5 py-3 text-sm font-extrabold text-[#470382]" type="button" @click="loadDetails">Показать отчет</button>
          </div>

          <div v-if="detailsLoading" class="rounded-2xl bg-surface-container p-5 text-sm text-on-muted">Загружаю отчет...</div>
          <div v-else-if="details" class="grid gap-7 lg:grid-cols-2">
            <div v-if="nutritionReportsEnabled">
              <h4 class="mb-4 text-xl font-extrabold">Отчет по питанию</h4>
              <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div v-for="item in [{ label: 'Ккал', value: details.food_summary.calories }, { label: 'Белки', value: details.food_summary.protein_g }, { label: 'Жиры', value: details.food_summary.fat_g }, { label: 'Углеводы', value: details.food_summary.carbs_g }]" :key="item.label" class="rounded-xl bg-surface-container p-3">
                  <span class="text-xs text-on-muted">{{ item.label }}</span><strong class="mt-1 block">{{ item.value }}</strong>
                </div>
              </div>
              <div class="grid max-h-[420px] gap-4 overflow-y-auto pr-1">
                <section v-for="[date, entries] in groupedFoodEntries" :key="date">
                  <h5 class="mb-2 text-sm font-extrabold text-primary">{{ formatDate(date) }}</h5>
                  <div class="grid gap-2">
                    <article v-for="entry in entries" :key="entry.id" class="rounded-xl border border-white/10 bg-surface-container p-3">
                      <div class="flex justify-between gap-3"><strong>{{ entry.title }}</strong><span class="shrink-0 text-sm text-primary">{{ entry.calories }} ккал</span></div>
                      <p class="mt-1 text-xs text-on-muted">{{ entry.meal_type }} · Б {{ entry.protein_g }} · Ж {{ entry.fat_g }} · У {{ entry.carbs_g }}</p>
                    </article>
                  </div>
                </section>
                <p v-if="!groupedFoodEntries.length" class="rounded-xl bg-surface-container p-4 text-sm text-on-muted">За выбранный период записей нет.</p>
              </div>
            </div>

            <div>
              <h4 class="mb-4 text-xl font-extrabold">Комментарии клиенту</h4>
              <form class="mb-4 grid gap-3" @submit.prevent="addComment">
                <textarea v-model.trim="commentBody" required class="min-h-28 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" placeholder="Напишите рекомендации или комментарий к отчету" />
                <button class="rounded-xl bg-primary px-5 py-3 text-sm font-extrabold text-[#470382] disabled:opacity-50" type="submit" :disabled="commentSaving">{{ commentSaving ? 'Отправка...' : 'Отправить клиенту' }}</button>
              </form>
              <div class="grid max-h-[420px] gap-3 overflow-y-auto pr-1">
                <article v-for="comment in details.comments" :key="comment.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
                  <div class="flex items-center justify-between gap-3 text-xs"><strong class="text-primary">{{ comment.author?.name }}</strong><span class="text-on-muted">{{ formatDate(comment.created_at) }}</span></div>
                  <p class="mt-2 text-sm leading-6 text-on-surface">{{ comment.body }}</p>
                </article>
                <p v-if="!details.comments.length" class="rounded-xl bg-surface-container p-4 text-sm text-on-muted">Комментариев пока нет.</p>
              </div>
            </div>

            <div class="lg:col-span-2">
              <div class="mb-4 flex items-center justify-between gap-3">
                <h4 class="text-xl font-extrabold">Замеры участницы</h4>
                <span class="material-symbols-outlined text-primary">monitoring</span>
              </div>
              <div v-if="details.measurements.length" class="grid max-h-[360px] gap-3 overflow-y-auto pr-1 sm:grid-cols-2">
                <article v-for="measurement in details.measurements" :key="measurement.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
                  <div class="flex items-center justify-between gap-3">
                    <strong class="text-primary">{{ formatDate(measurement.measured_on) }}</strong>
                    <span v-if="measurement.mood" class="text-xs text-on-muted">{{ measurement.mood }}</span>
                  </div>
                  <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <span>Вес <b class="block text-base">{{ measurement.weight_kg }} кг</b></span>
                    <span v-if="measurement.waist_cm != null">Талия <b class="block text-base">{{ measurement.waist_cm }} см</b></span>
                    <span v-if="measurement.chest_cm != null">Грудь <b class="block text-base">{{ measurement.chest_cm }} см</b></span>
                    <span v-if="measurement.hips_cm != null">Бёдра <b class="block text-base">{{ measurement.hips_cm }} см</b></span>
                  </div>
                  <p v-if="measurement.comment" class="mt-3 border-t border-white/10 pt-3 text-sm leading-6 text-on-muted">{{ measurement.comment }}</p>
                </article>
              </div>
              <p v-else class="rounded-xl bg-surface-container p-4 text-sm text-on-muted">За выбранный период замеров нет.</p>
            </div>
          </div>
        </section>
      </div>
    </Teleport>
  </section>
</template>
