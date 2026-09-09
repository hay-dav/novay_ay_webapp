<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '@/services/api';

const loading = ref(true);
const saving = ref(false);
const deletingScheduled = ref(false);
const grantModalOpen = ref(false);
const users = ref([]);
const expired = ref([]);
const scheduled = ref([]);
const active = ref([]);
  const activeCount = ref(0);
  const userSearch = ref('');
  const expiredSearch = ref('');
  const activeSearch = ref('');
const selectedUserIds = ref([]);
const selectedScheduledIds = ref([]);
const scheduledErrorMessage = ref('');
const activeErrorMessage = ref('');
const updatingActivePeriodId = ref(null);
const extensionMonths = ref({});
const errorMessage = ref('');
const successMessage = ref('');
const grantForm = ref({ starts_on: '', months: 1 });

function localDateKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function availableStartDates() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dates = [];
    for (let monthOffset = -1; monthOffset < 2; monthOffset += 1) {
        for (const day of [2, 15]) {
            const date = new Date(today.getFullYear(), today.getMonth() + monthOffset, day);
            dates.push(date);
        }
    }
    const previousDates = dates.filter((date) => date < today).slice(-2);
    const currentAndFutureDates = dates.filter((date) => date >= today);

    return [...previousDates, ...currentAndFutureDates].map((date) => ({
        value: localDateKey(date),
        label: new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(date),
    }));
}

const startDateOptions = availableStartDates();
const filteredUsers = computed(() => {
    const query = userSearch.value.trim().toLocaleLowerCase('ru-RU');
    if (!query)
        return users.value;
    return users.value.filter((user) => user.name.toLocaleLowerCase('ru-RU').includes(query));
});
  const filteredExpired = computed(() => {
      const query = expiredSearch.value.trim().toLocaleLowerCase('ru-RU');
      if (!query)
          return expired.value;
      return expired.value.filter((item) => item.name.toLocaleLowerCase('ru-RU').includes(query));
  });
  const filteredActive = computed(() => {
      const query = activeSearch.value.trim().toLocaleLowerCase('ru-RU');
      if (!query)
          return active.value;
      return active.value.filter((item) => item.name.toLocaleLowerCase('ru-RU').includes(query));
  });
const scheduledOnSecond = computed(() => scheduled.value.filter((item) => Number(item.starts_on?.slice(-2)) === 2));
const scheduledOnFifteenth = computed(() => scheduled.value.filter((item) => Number(item.starts_on?.slice(-2)) === 15));
const allVisibleSelected = computed(() => filteredUsers.value.length > 0
    && filteredUsers.value.every((user) => selectedUserIds.value.includes(user.id)));
const calculatedEndDate = computed(() => {
    if (!grantForm.value.starts_on || !grantForm.value.months)
        return '';
    const [year, month, day] = grantForm.value.starts_on.split('-').map(Number);
    const endDate = new Date(year, month - 1 + Number(grantForm.value.months), day);
    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(endDate);
});

async function loadAccessData() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/access-management');
        users.value = data.data.users ?? [];
        expired.value = data.data.expired ?? [];
        scheduled.value = data.data.scheduled ?? [];
        active.value = data.data.active ?? [];
        selectedScheduledIds.value = selectedScheduledIds.value.filter((periodId) => scheduled.value.some((item) => item.period_id === periodId));
        activeCount.value = Number(data.data.active_count ?? 0);
    }
    finally {
        loading.value = false;
    }
}

function openGrantModal(userId = null) {
    selectedUserIds.value = userId ? [Number(userId)] : [];
    userSearch.value = '';
    errorMessage.value = '';
    successMessage.value = '';
    grantForm.value = {
        starts_on: startDateOptions[0]?.value ?? '',
        months: 1,
    };
    grantModalOpen.value = true;
}

function closeGrantModal() {
    if (!saving.value)
        grantModalOpen.value = false;
}

function toggleAllVisible() {
    const visibleIds = filteredUsers.value.map((user) => user.id);
    if (allVisibleSelected.value) {
        selectedUserIds.value = selectedUserIds.value.filter((id) => !visibleIds.includes(id));
        return;
    }
    selectedUserIds.value = [...new Set([...selectedUserIds.value, ...visibleIds])];
}

function formatDate(value) {
    if (!value)
        return '—';
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`));
}

async function grantAccess() {
    errorMessage.value = '';
    successMessage.value = '';
    if (!selectedUserIds.value.length) {
        errorMessage.value = 'Выберите хотя бы одного участника.';
        return;
    }
    saving.value = true;
    try {
        const { data } = await api.post('/admin/access-management/grant', {
            user_ids: selectedUserIds.value,
            starts_on: grantForm.value.starts_on,
            months: Number(grantForm.value.months),
        });
        successMessage.value = `Доступ назначен: ${data.data.granted_count}. Дата окончания — ${formatDate(data.data.ends_on)}.`;
        await loadAccessData();
        window.setTimeout(() => {
            grantModalOpen.value = false;
            successMessage.value = '';
        }, 900);
    }
    catch (error) {
        const validationErrors = error.response?.data?.errors;
        errorMessage.value = validationErrors
            ? Object.values(validationErrors).flat()[0]
            : error.response?.data?.message ?? 'Не удалось назначить доступ.';
    }
    finally {
        saving.value = false;
    }
}

async function deleteSelectedScheduled() {
    if (!selectedScheduledIds.value.length || deletingScheduled.value)
        return;

    const confirmed = window.confirm(`Удалить выбранные запланированные старты: ${selectedScheduledIds.value.length}?`);
    if (!confirmed)
        return;

    deletingScheduled.value = true;
    scheduledErrorMessage.value = '';
    try {
        await api.delete('/admin/access-management/scheduled', {
            data: { period_ids: selectedScheduledIds.value },
        });
        selectedScheduledIds.value = [];
        await loadAccessData();
    }
    catch (error) {
        const validationErrors = error.response?.data?.errors;
        scheduledErrorMessage.value = validationErrors
            ? Object.values(validationErrors).flat()[0]
            : error.response?.data?.message ?? 'Не удалось удалить запланированные старты.';
    }
    finally {
        deletingScheduled.value = false;
    }
}

async function extendActiveAccess(item) {
    const months = Number(extensionMonths.value[item.period_id] ?? 1);
    if (!Number.isInteger(months) || months < 1 || months > 24) {
        activeErrorMessage.value = 'Укажите от 1 до 24 месяцев для продления.';
        return;
    }

    updatingActivePeriodId.value = item.period_id;
    activeErrorMessage.value = '';
    try {
        await api.patch(`/admin/access-management/active/${item.period_id}`, {
            action: 'extend',
            months,
        });
        await loadAccessData();
    }
    catch (error) {
        activeErrorMessage.value = error.response?.data?.message ?? 'Не удалось продлить доступ.';
    }
    finally {
        updatingActivePeriodId.value = null;
    }
}

async function revokeActiveAccess(item) {
    if (!window.confirm(`Отключить полный доступ для «${item.name}»?`))
        return;

    updatingActivePeriodId.value = item.period_id;
    activeErrorMessage.value = '';
    try {
        await api.patch(`/admin/access-management/active/${item.period_id}`, { action: 'revoke' });
        await loadAccessData();
    }
    catch (error) {
        activeErrorMessage.value = error.response?.data?.message ?? 'Не удалось отключить доступ.';
    }
    finally {
        updatingActivePeriodId.value = null;
    }
}

onMounted(loadAccessData);
</script>

<template>
  <section class="grid gap-6">
    <header>
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Управление участниками</p>
      <h2 class="mt-2 text-[32px] font-extrabold leading-10">Доступы</h2>
      <p class="mt-2 max-w-2xl text-sm leading-6 text-on-muted">Назначайте полный доступ к платным материалам с началом 2-го или 15-го числа месяца.</p>
    </header>

    <div v-if="loading" class="glass-panel rounded-[28px] p-6 text-sm text-on-muted">Загружаем данные о доступах…</div>

    <div v-else class="grid gap-5 lg:grid-cols-2">
      <button class="glass-panel group min-h-64 rounded-[28px] p-6 text-left transition hover:-translate-y-0.5 hover:border-primary/40" type="button" @click="openGrantModal()">
        <div class="flex items-start justify-between gap-5">
          <div>
            <span class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/15 text-primary"><span class="material-symbols-outlined text-[30px]">key</span></span>
            <h3 class="mt-6 text-2xl font-extrabold">Доступы</h3>
            <p class="mt-2 text-sm leading-6 text-on-muted">Выберите участников, дату старта и количество месяцев полного доступа.</p>
          </div>
          <span class="rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary">{{ activeCount }} участниц</span>
        </div>
        <span class="mt-7 inline-flex items-center gap-2 text-sm font-extrabold text-primary">Открыть управление <span class="material-symbols-outlined text-[18px] transition group-hover:translate-x-1">arrow_forward</span></span>
      </button>

      <article class="glass-panel min-h-64 rounded-[28px] p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <span class="grid h-14 w-14 place-items-center rounded-2xl bg-red-500/10 text-red-200"><span class="material-symbols-outlined text-[30px]">event_busy</span></span>
            <h3 class="mt-6 text-2xl font-extrabold">Закончился доступ</h3>
          </div>
          <span class="rounded-full border border-red-300/20 bg-red-500/10 px-3 py-1 text-xs font-extrabold text-red-200">{{ expired.length }}</span>
        </div>

        <label v-if="expired.length" class="relative mt-5 block">
          <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-on-muted">search</span>
          <input v-model.trim="expiredSearch" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-12 pr-4 text-sm text-on-surface outline-none focus:border-primary/50" type="search" placeholder="Поиск по имени или фамилии" />
        </label>

        <div v-if="filteredExpired.length" class="brand-scrollbar mt-4 grid w-full min-w-0 max-h-80 gap-3 overflow-x-hidden overflow-y-auto pr-1">
          <div v-for="item in filteredExpired" :key="item.period_id" class="min-w-0 overflow-hidden rounded-2xl border border-white/10 bg-surface-container p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div class="min-w-0 flex-1"><strong class="block truncate">{{ item.name }}</strong><span class="mt-1 block text-xs text-on-muted">Закончился {{ formatDate(item.ends_on) }}</span></div>
              <button class="shrink-0 rounded-xl border border-primary/30 px-4 py-2 text-xs font-extrabold text-primary transition hover:bg-primary/10" type="button" @click="openGrantModal(item.user_id)">Продлить доступ</button>
            </div>
          </div>
        </div>
        <p v-else class="mt-5 rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">{{ expired.length ? 'По вашему запросу ничего не найдено.' : 'Завершённых периодов доступа пока нет.' }}</p>
      </article>

      <article class="glass-panel min-h-64 rounded-[28px] p-6 lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <span class="grid h-14 w-14 place-items-center rounded-2xl bg-emerald-500/10 text-emerald-200"><span class="material-symbols-outlined text-[30px]">event_available</span></span>
            <h3 class="mt-6 text-2xl font-extrabold">Активные доступы</h3>
            <p class="mt-2 text-sm leading-6 text-on-muted">Продлевайте срок активного доступа или отключайте его полностью.</p>
          </div>
          <span class="rounded-full border border-emerald-300/20 bg-emerald-500/10 px-3 py-1 text-xs font-extrabold text-emerald-200">{{ active.length }}</span>
        </div>

          <p v-if="activeErrorMessage" class="mt-4 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200">{{ activeErrorMessage }}</p>

          <label v-if="active.length" class="relative mt-5 block">
            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-on-muted">search</span>
            <input v-model.trim="activeSearch" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-12 pr-4 text-sm text-on-surface outline-none focus:border-primary/50" type="search" placeholder="Поиск по имени или фамилии" />
          </label>

        <div v-if="filteredActive.length" class="brand-scrollbar mt-5 grid w-full min-w-0 max-h-[34rem] gap-3 overflow-x-hidden overflow-y-auto pr-1">
          <div v-for="item in filteredActive" :key="item.period_id" class="min-w-0 overflow-hidden rounded-2xl border border-white/10 bg-surface-container p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <div class="min-w-0 flex-1"><strong class="block truncate">{{ item.name }}</strong><span class="mt-1 block text-xs text-on-muted">С {{ formatDate(item.starts_on) }} до {{ formatDate(item.ends_on) }} · {{ item.months }} мес.</span></div>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label class="flex items-center gap-2 text-xs font-bold text-on-muted">Продлить на
                  <input v-model.number="extensionMonths[item.period_id]" class="w-16 rounded-xl border border-white/10 bg-surface-low px-2 py-2 text-center text-sm text-on-surface outline-none focus:border-primary/50" type="number" min="1" max="24" placeholder="1" :disabled="updatingActivePeriodId === item.period_id" /> мес.
                </label>
                <button class="rounded-xl border border-primary/30 px-4 py-2 text-xs font-extrabold text-primary transition hover:bg-primary/10 disabled:cursor-not-allowed disabled:opacity-50" type="button" :disabled="updatingActivePeriodId === item.period_id" @click="extendActiveAccess(item)">{{ updatingActivePeriodId === item.period_id ? 'Сохраняем…' : 'Продлить' }}</button>
                <button class="rounded-xl border border-red-300/25 px-4 py-2 text-xs font-extrabold text-red-200 transition hover:bg-red-500/10 disabled:cursor-not-allowed disabled:opacity-50" type="button" :disabled="updatingActivePeriodId === item.period_id" @click="revokeActiveAccess(item)">Отключить</button>
              </div>
            </div>
          </div>
        </div>
        <p v-else class="mt-5 rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">{{ active.length ? 'По вашему запросу ничего не найдено.' : 'Активных срочных доступов пока нет.' }}</p>
      </article>

      <article class="glass-panel min-h-64 rounded-[28px] p-6 lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <span class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/15 text-primary"><span class="material-symbols-outlined text-[30px]">event_upcoming</span></span>
            <h3 class="mt-6 text-2xl font-extrabold">Запланированные</h3>
            <p class="mt-2 text-sm leading-6 text-on-muted">Доступы, которые включатся автоматически в выбранную дату старта.</p>
          </div>
          <div class="flex flex-wrap items-center justify-end gap-3">
            <span class="rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary">{{ scheduled.length }}</span>
            <button
              v-if="scheduled.length"
              class="min-h-10 rounded-xl border border-red-300/25 px-4 py-2 text-xs font-extrabold text-red-200 transition hover:bg-red-500/10 disabled:cursor-not-allowed disabled:opacity-40"
              type="button"
              :disabled="!selectedScheduledIds.length || deletingScheduled"
              @click="deleteSelectedScheduled"
            >
              {{ deletingScheduled ? 'Удаляем…' : `Удалить выбранные (${selectedScheduledIds.length})` }}
            </button>
          </div>
        </div>

        <p v-if="scheduledErrorMessage" class="mt-4 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200">{{ scheduledErrorMessage }}</p>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
          <section class="rounded-2xl border border-white/10 bg-surface-low/60 p-4" aria-labelledby="scheduled-second-title">
            <div class="flex items-center justify-between gap-3">
              <h4 id="scheduled-second-title" class="font-extrabold">Запланированы на 2-е число</h4>
              <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary">{{ scheduledOnSecond.length }}</span>
            </div>
            <div v-if="scheduledOnSecond.length" class="brand-scrollbar mt-4 grid max-h-80 gap-3 overflow-y-auto pr-1">
              <label v-for="item in scheduledOnSecond" :key="item.period_id" class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-surface-container p-4 transition hover:border-primary/30">
                <input v-model="selectedScheduledIds" class="mt-0.5 h-5 w-5 shrink-0 accent-[#c992ff]" type="checkbox" :value="item.period_id" :aria-label="`Выбрать запланированный старт для ${item.name}`" />
                <span class="min-w-0"><strong class="block truncate">{{ item.name }}</strong><span class="mt-1 block text-xs text-on-muted">Старт {{ formatDate(item.starts_on) }}</span><span class="mt-1 block text-xs text-on-muted">До {{ formatDate(item.ends_on) }} · {{ item.months }} мес.</span></span>
              </label>
            </div>
            <p v-else class="mt-4 rounded-2xl bg-surface-container p-4 text-sm text-on-muted">Запланированных доступов на 2-е число пока нет.</p>
          </section>

          <section class="rounded-2xl border border-white/10 bg-surface-low/60 p-4" aria-labelledby="scheduled-fifteenth-title">
            <div class="flex items-center justify-between gap-3">
              <h4 id="scheduled-fifteenth-title" class="font-extrabold">Запланированы на 15-е число</h4>
              <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary">{{ scheduledOnFifteenth.length }}</span>
            </div>
            <div v-if="scheduledOnFifteenth.length" class="brand-scrollbar mt-4 grid max-h-80 gap-3 overflow-y-auto pr-1">
              <label v-for="item in scheduledOnFifteenth" :key="item.period_id" class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/10 bg-surface-container p-4 transition hover:border-primary/30">
                <input v-model="selectedScheduledIds" class="mt-0.5 h-5 w-5 shrink-0 accent-[#c992ff]" type="checkbox" :value="item.period_id" :aria-label="`Выбрать запланированный старт для ${item.name}`" />
                <span class="min-w-0"><strong class="block truncate">{{ item.name }}</strong><span class="mt-1 block text-xs text-on-muted">Старт {{ formatDate(item.starts_on) }}</span><span class="mt-1 block text-xs text-on-muted">До {{ formatDate(item.ends_on) }} · {{ item.months }} мес.</span></span>
              </label>
            </div>
            <p v-else class="mt-4 rounded-2xl bg-surface-container p-4 text-sm text-on-muted">Запланированных доступов на 15-е число пока нет.</p>
          </section>
        </div>
      </article>
    </div>

    <Teleport to="body">
      <div v-if="grantModalOpen" class="app-modal-backdrop z-[120] bg-black/70 backdrop-blur-sm" @mousedown.self="closeGrantModal">
        <form class="app-modal-panel glass-panel overflow-x-hidden rounded-[28px] p-5 sm:max-w-3xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="grant-access-title" @submit.prevent="grantAccess">
          <div class="mb-6 flex items-start justify-between gap-4">
            <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Полный доступ</p><h3 id="grant-access-title" class="mt-1 text-2xl font-extrabold">Назначить доступ</h3></div>
            <button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" :disabled="saving" aria-label="Закрыть" @click="closeGrantModal"><span class="material-symbols-outlined">close</span></button>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <label class="grid gap-2 text-sm font-bold text-on-muted">Дата старта
              <select v-model="grantForm.starts_on" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required>
                <option v-for="date in startDateOptions" :key="date.value" :value="date.value">{{ date.label }}</option>
              </select>
            </label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Количество месяцев
              <input v-model.number="grantForm.months" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" min="1" max="24" required />
            </label>
          </div>
          <p class="mt-3 rounded-2xl border border-primary/20 bg-primary/10 px-4 py-3 text-sm text-on-surface">Доступ закончится <strong class="text-primary">{{ calculatedEndDate }}</strong>.</p>

          <div class="mt-6 border-t border-white/10 pt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <label class="relative block flex-1"><span class="sr-only">Поиск участников</span><span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-on-muted">search</span><input v-model.trim="userSearch" class="w-full rounded-2xl border border-white/10 bg-surface-low py-3 pl-12 pr-4 text-sm text-on-surface outline-none focus:border-primary/50" type="search" placeholder="Поиск по имени или фамилии" /></label>
              <button class="rounded-xl border border-white/10 px-4 py-3 text-xs font-extrabold text-primary" type="button" @click="toggleAllVisible">{{ allVisibleSelected ? 'Снять выбранных' : 'Выбрать найденных' }}</button>
            </div>
            <p class="mt-3 text-xs font-semibold text-on-muted">Выбрано участников: <span class="text-primary">{{ selectedUserIds.length }}</span></p>

            <div class="brand-scrollbar mt-3 grid max-h-72 min-w-0 w-full gap-2 overflow-x-hidden overflow-y-auto pr-1">
              <label v-for="user in filteredUsers" :key="user.id" class="flex w-full min-w-0 cursor-pointer items-center gap-3 overflow-hidden rounded-2xl border border-white/10 bg-surface-container p-3 transition hover:border-primary/30">
                <input v-model="selectedUserIds" class="h-5 w-5 accent-[#c992ff]" type="checkbox" :value="user.id" />
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/15 text-primary"><span class="material-symbols-outlined text-[20px]">person</span></span>
                <span class="min-w-0 flex-1 overflow-hidden"><strong class="block truncate text-sm">{{ user.name }}</strong><span class="block truncate text-xs" :class="user.access_status === 'paid' ? 'text-primary' : 'text-on-muted'">{{ user.access_status === 'paid' ? 'Полный доступ включён' : 'Доступ ограничен' }}</span></span>
              </label>
              <p v-if="!filteredUsers.length" class="rounded-2xl bg-surface-container p-4 text-sm text-on-muted">Участники не найдены.</p>
            </div>
          </div>

          <p v-if="errorMessage" class="mt-5 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200">{{ errorMessage }}</p>
          <p v-if="successMessage" class="mt-5 rounded-2xl border border-emerald-300/25 bg-emerald-500/10 p-3 text-sm font-semibold text-emerald-200">{{ successMessage }}</p>
          <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button class="rounded-2xl border border-white/10 px-5 py-3 text-sm font-bold text-on-muted" type="button" :disabled="saving" @click="closeGrantModal">Отмена</button>
            <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-3 text-sm font-extrabold text-white disabled:opacity-60" type="submit" :disabled="saving">{{ saving ? 'Назначаем…' : 'Дать доступ' }}</button>
          </div>
        </form>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.brand-scrollbar {
  scrollbar-width: thin;
  scrollbar-color: #8c55c7 #211e25;
}

.brand-scrollbar::-webkit-scrollbar {
  width: 8px;
  height: 0;
}

.brand-scrollbar::-webkit-scrollbar-track {
  background: #211e25;
  border-radius: 999px;
}

.brand-scrollbar::-webkit-scrollbar-thumb {
  border: 2px solid #211e25;
  border-radius: 999px;
  background: linear-gradient(#dbb8ff, #8c55c7);
}
</style>
