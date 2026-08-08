<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/services/api';
const backendOrigin = api.defaults.baseURL.replace(/\/api\/v1\/?$/, '');
const route = useRoute();
const participants = ref([]);
const participantsCount = ref(0);
const searchQuery = ref('');
const activeFilter = ref('all');
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
const filterOptions = [
    { value: 'all', label: 'Все' },
    { value: 'paid', label: 'Платные' },
    { value: 'free', label: 'Бесплатные' },
    { value: 'new', label: 'Новые' },
];
const newParticipantThreshold = Date.now() - 7 * 86400000;
const filteredParticipants = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase('ru-RU');
    return participants.value.filter((participant) => {
        const matchesFilter = activeFilter.value === 'all'
            || (activeFilter.value === 'new'
                ? new Date(participant.created_at).getTime() >= newParticipantThreshold
                : participant.access_status === activeFilter.value);
        const matchesSearch = !query || [
            participant.first_name,
            participant.last_name,
            participant.name,
        ].filter(Boolean).join(' ').toLocaleLowerCase('ru-RU').includes(query);
        return matchesFilter && matchesSearch;
    });
});
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
        const { data } = await api.get('/admin/dashboard', { params: { all_clients: 1 } });
        participants.value = data.data.clients;
        participantsCount.value = data.data.clients_count ?? participants.value.length;
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
    if (!path)
        return '';
    return /^https?:\/\//i.test(path)
        ? path
        : `${backendOrigin}/${String(path).replace(/^\/+/, '')}`;
}
onMounted(load);
</script>

<template>
  <section class="grid min-w-0 gap-6 overflow-x-hidden">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Сопровождение</p>
        <h2 class="mt-2 text-[32px] font-extrabold leading-10">Участницы</h2>
      </div>
      <span class="inline-flex items-center gap-2 rounded-2xl border border-primary/20 bg-primary/10 px-4 py-2 text-sm font-extrabold text-primary"><span class="material-symbols-outlined text-[18px]">groups</span>{{ participantsCount }} {{ participantsCount === 1 ? 'участница' : 'участниц' }}</span>
    </div>

    <div class="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
      <label class="relative block min-w-0 max-w-xl">
        <span class="sr-only">Поиск участниц</span>
        <span class="material-symbols-outlined pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-on-muted">search</span>
        <input v-model="searchQuery" class="w-full rounded-2xl border border-white/10 bg-surface-container py-3 pl-12 pr-4 text-on-surface outline-none transition placeholder:text-on-muted focus:border-primary/50" type="search" placeholder="Поиск по имени или фамилии" />
      </label>
      <div class="flex min-w-0 flex-wrap gap-2" role="group" aria-label="Фильтр участниц">
        <button
          v-for="option in filterOptions"
          :key="option.value"
          class="rounded-xl border px-4 py-2 text-sm font-extrabold transition"
          :class="activeFilter === option.value ? 'border-primary bg-primary text-[#470382]' : 'border-white/10 bg-surface-container text-on-muted hover:border-primary/30 hover:text-primary'"
          type="button"
          :aria-pressed="activeFilter === option.value"
          @click="activeFilter = option.value"
        >
          {{ option.label }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="glass-panel rounded-[28px] p-5 text-sm text-on-muted">Загружаю участниц...</div>

    <div v-else class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="participant in filteredParticipants" :key="participant.id" class="glass-panel min-w-0 overflow-hidden rounded-[28px] p-4 sm:p-5">
        <div class="flex min-w-0 items-start gap-3 sm:gap-4">
          <div v-if="participant.avatar_path" class="h-14 w-14 shrink-0 overflow-hidden rounded-full border border-primary/30 bg-surface-high">
            <img class="h-full w-full object-cover" :src="avatarUrl(participant.avatar_path)" :alt="`Аватар ${participant.name}`" />
          </div>
          <div v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary/15 text-primary">
            <span class="material-symbols-outlined">person</span>
          </div>
          <div class="min-w-0 flex-1">
            <h3 class="break-words text-lg font-extrabold leading-tight sm:text-xl">{{ participant.name }}</h3>
            <p class="mt-1 break-all text-sm leading-5 text-on-muted">{{ participant.email }}</p>
            <p class="mt-1 break-words text-sm leading-5 text-on-muted">{{ participant.phone ?? 'Телефон не указан' }}</p>
            <p class="mt-2 text-sm leading-6 text-on-muted">{{ participant.client_profile?.goal ?? 'Цель не указана' }}</p>
          </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">
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

        <div class="mt-3 grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">
          <button class="min-w-0 rounded-xl px-4 py-2 text-sm font-extrabold whitespace-normal" :class="participant.access_status === 'paid' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(participant, 'paid')">Доступ открыт</button>
          <button class="min-w-0 rounded-xl px-4 py-2 text-sm font-extrabold whitespace-normal" :class="participant.access_status === 'free' ? 'bg-primary text-[#470382]' : 'border border-white/10 text-on-muted'" @click="setAccess(participant, 'free')">Ограничить</button>
        </div>
      </article>
      <p v-if="!filteredParticipants.length" class="glass-panel rounded-[28px] p-5 text-sm text-on-muted md:col-span-2 xl:col-span-3">По вашему запросу участниц не найдено.</p>
    </div>

    <Teleport to="body">
      <div v-if="selectedParticipant" class="app-modal-backdrop z-[100] bg-black/65 backdrop-blur-sm" @mousedown.self="closeDetails">
        <section class="app-modal-panel glass-panel min-w-0 rounded-[28px] p-5 pb-8 sm:max-w-4xl sm:p-7" role="dialog" aria-modal="true" aria-label="Отчеты участницы">
          <header class="mb-6 flex items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Участница</p>
              <h3 class="mt-1 break-words text-xl font-extrabold sm:text-2xl">{{ selectedParticipant.name }}</h3>
            </div>
            <button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" @click="closeDetails"><span class="material-symbols-outlined">close</span></button>
          </header>

          <div class="mb-6 flex min-w-0 flex-col gap-3 rounded-2xl bg-surface-container p-4 sm:flex-row sm:items-end">
            <label class="grid min-w-0 flex-1 gap-1 text-xs font-bold text-on-muted">С даты<input v-model="reportRange.from" class="w-full min-w-0 rounded-xl border border-white/10 bg-surface-low px-3 py-2 text-on-surface" type="date" /></label>
            <label class="grid min-w-0 flex-1 gap-1 text-xs font-bold text-on-muted">По дату<input v-model="reportRange.to" class="w-full min-w-0 rounded-xl border border-white/10 bg-surface-low px-3 py-2 text-on-surface" type="date" /></label>
            <button class="w-full shrink-0 rounded-xl bg-primary px-5 py-3 text-sm font-extrabold text-[#470382] sm:w-auto" type="button" @click="loadDetails">Показать отчет</button>
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
