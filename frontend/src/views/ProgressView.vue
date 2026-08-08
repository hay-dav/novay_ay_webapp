<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import ProgressChart from '@/components/ProgressChart.vue';
const entries = ref([]);
const form = ref({
    weight_kg: null,
    waist_cm: null,
    chest_cm: null,
    hips_cm: null,
    mood: 'Энергично',
    measured_on: new Date().toISOString().slice(0, 10),
});
const mode = ref('chart');
const saving = ref(false);
const saved = ref(false);
let savedTimer;
const orderedEntries = computed(() => [...entries.value].sort((a, b) => {
    const dateDifference = new Date(a.measured_on) - new Date(b.measured_on);
    return dateDifference || a.id - b.id;
}));
const latestEntry = computed(() => orderedEntries.value.at(-1));
const previousEntry = computed(() => orderedEntries.value.at(-2));

const weightTrend = computed(() => {
    if (!latestEntry.value || !previousEntry.value)
        return '—';

    const currentWeight = Number(previousEntry.value.weight_kg);
    const newWeight = Number(latestEntry.value.weight_kg);
    if (!Number.isFinite(currentWeight) || !Number.isFinite(newWeight))
        return '—';

    const difference = Math.abs(currentWeight - newWeight).toFixed(1);
    if (newWeight > currentWeight)
        return `+${difference} кг`;
    if (newWeight < currentWeight)
        return `-${difference} кг`;
    return '0.0 кг';
});
async function load() {
    const { data } = await api.get('/progress');
    entries.value = data.data;
}
async function submit() {
    if (saving.value)
        return;
    saving.value = true;
    saved.value = false;
    window.clearTimeout(savedTimer);
    try {
        const { data } = await api.post('/progress', form.value);
        entries.value = [...entries.value, data.data];
        saved.value = true;
        savedTimer = window.setTimeout(() => {
            saved.value = false;
        }, 2500);
    }
    finally {
        saving.value = false;
    }
}
onMounted(load);
onBeforeUnmount(() => window.clearTimeout(savedTimer));
</script>

<template>
  <section class="grid gap-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">замеры</p>
        <h2 class="mt-2 text-[32px] font-extrabold leading-10">Замеры и прогресс</h2>
      </div>
      <div class="grid grid-cols-2 gap-1 rounded-2xl bg-surface-container p-1 lg:w-[260px]">
        <button class="rounded-xl px-4 py-2 text-sm font-bold" :class="mode === 'chart' ? 'bg-primary text-[#470382]' : 'text-on-muted'" @click="mode = 'chart'">
          График
        </button>
        <button class="rounded-xl px-4 py-2 text-sm font-bold" :class="mode === 'history' ? 'bg-primary text-[#470382]' : 'text-on-muted'" @click="mode = 'history'">
          История
        </button>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[360px_1fr]">
      <article class="glass-panel rounded-[28px] p-5">
        <h3 class="mb-5 text-xl font-extrabold">Новый замер</h3>
        <form class="grid gap-4" @submit.prevent="submit">
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Вес
            <input v-model.number="form.weight_kg" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" step="0.1" placeholder="кг" />
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Талия
            <input v-model.number="form.waist_cm" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" step="0.1" placeholder="см" />
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Грудь
            <input v-model.number="form.chest_cm" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" min="20" max="250" step="0.1" placeholder="см" />
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Бёдра
            <input v-model.number="form.hips_cm" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" min="20" max="250" step="0.1" placeholder="см" />
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Настроение
            <input v-model="form.mood" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="text" />
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Дата
            <input v-model="form.measured_on" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="date" />
          </label>
          <button class="rounded-2xl px-6 py-4 font-extrabold text-white transition duration-150 active:scale-[0.98] disabled:cursor-wait disabled:opacity-70" :class="saved ? 'bg-emerald-600 shadow-[0_8px_22px_rgba(16,185,129,0.28)]' : 'bg-gradient-to-br from-primary-container to-primary-strong'" type="submit" :disabled="saving">
            {{ saving ? 'Сохраняем...' : saved ? '✓ Сохранено' : 'Сохранить' }}
          </button>
        </form>
      </article>

      <article class="glass-panel rounded-[28px] p-5">
        <template v-if="mode === 'chart'">
          <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="rounded-2xl bg-surface-container p-4">
              <span class="text-xs text-on-muted">Вес</span>
              <strong class="mt-1 block text-xl text-primary">{{ entries.at(-1)?.weight_kg ?? form.weight_kg }} кг</strong>
            </div>
            <div class="rounded-2xl bg-surface-container p-4">
              <span class="text-xs text-on-muted">Талия</span>
              <strong class="mt-1 block text-xl text-primary">{{ entries.at(-1)?.waist_cm ?? form.waist_cm }} см</strong>
            </div>
            <div class="rounded-2xl bg-surface-container p-4">
              <span class="text-xs text-on-muted">Грудь</span>
              <strong class="mt-1 block text-xl text-primary">{{ entries.at(-1)?.chest_cm ?? form.chest_cm ?? '—' }}<template v-if="entries.at(-1)?.chest_cm ?? form.chest_cm"> см</template></strong>
            </div>
            <div class="rounded-2xl bg-surface-container p-4">
              <span class="text-xs text-on-muted">Бёдра</span>
              <strong class="mt-1 block text-xl text-primary">{{ entries.at(-1)?.hips_cm ?? form.hips_cm ?? '—' }}<template v-if="entries.at(-1)?.hips_cm ?? form.hips_cm"> см</template></strong>
            </div>
            <div class="rounded-2xl bg-surface-container p-4">
              <span class="text-xs text-on-muted">Тренд</span>
              <strong class="mt-1 block text-xl text-primary">{{ weightTrend }}</strong>
            </div>
          </div>
          <ProgressChart :entries="entries" />
        </template>

        <div v-else class="grid gap-4">
          <article v-for="entry in [...entries].reverse()" :key="entry.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
            <div class="flex items-center justify-between">
              <strong>{{ new Date(entry.measured_on).toLocaleDateString('ru-RU') }}</strong>
              <span class="rounded-lg bg-primary/10 px-3 py-1 text-xs font-bold text-primary">{{ entry.mood ?? 'замер' }}</span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
              <div><span class="block text-on-muted">Вес</span><b>{{ entry.weight_kg }} кг</b></div>
              <div><span class="block text-on-muted">Талия</span><b>{{ entry.waist_cm ?? '-' }} см</b></div>
              <div><span class="block text-on-muted">Грудь</span><b>{{ entry.chest_cm ?? '-' }} см</b></div>
              <div><span class="block text-on-muted">Бёдра</span><b>{{ entry.hips_cm ?? '-' }} см</b></div>
            </div>
          </article>
        </div>
      </article>
    </div>
  </section>
</template>
