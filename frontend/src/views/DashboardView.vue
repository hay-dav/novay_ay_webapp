<script setup>
import { onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import StatTile from '@/components/StatTile.vue';
import ProgressChart from '@/components/ProgressChart.vue';
const auth = useAuthStore();
const notifications = ref([]);
const clientOverview = ref({ completed_workouts_count: 0 });
const dashboard = ref({
    clients: 0,
    revenue_cents: 0,
    active_courses: 0,
    pending_reviews: 0,
    clients_list: [],
    report_queue: [],
});
const questionnaire = ref('Цель, ограничения по питанию, опыт тренировок');
const photoPath = ref('progress/my-before-photo.jpg');
const requestSent = ref(false);
onMounted(async () => {
    if (auth.isTrainer) {
        const { data } = await api.get('/trainer/dashboard');
        dashboard.value = data.data;
        return;
    }
    const [notificationResponse, summaryResponse] = await Promise.all([
        api.get('/notifications').catch(() => ({ data: { data: [] } })),
        api.get('/workouts/summary').catch(() => ({ data: { data: { completed_workouts_count: 0 } } })),
    ]);
    notifications.value = notificationResponse.data.data;
    clientOverview.value = summaryResponse.data.data;
});
async function sendAccessRequest() {
    await api.post('/access-requests', { questionnaire: questionnaire.value, photo_path: photoPath.value });
    requestSent.value = true;
}
</script>

<template>
  <section v-if="auth.isTrainer" class="grid gap-5 lg:grid-cols-2">

    <article v-if="false" class="glass-panel rounded-[28px] p-5">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Мой доступ и уроки</h2>
        <span class="material-symbols-outlined text-primary">workspace_premium</span>
      </div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
        <div class="rounded-2xl border border-primary/20 bg-primary/10 p-4">
          <span class="text-xs font-bold uppercase tracking-wide text-on-muted">Доступ к материалам</span>
          <strong class="mt-2 block text-lg text-primary">{{ auth.user?.access_status === 'paid' ? 'Платный доступ' : 'Бесплатный доступ' }}</strong>
        </div>
        <div class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <span class="text-xs font-bold uppercase tracking-wide text-on-muted">Пройдено тренировок</span>
          <strong class="mt-2 block text-3xl text-on-surface">{{ clientOverview.completed_workouts_count }}</strong>
          <p class="mt-1 text-sm text-on-muted">Отмечено во вкладке «Тренировки»</p>
        </div>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5">
      <div class="mb-5 flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">сопровождение</p>
          <h2 class="mt-1 text-2xl font-extrabold">Клиенты</h2>
        </div>
        <RouterLink to="/participants" class="rounded-2xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]">Открыть</RouterLink>
      </div>
      <div class="dashboard-scroll grid max-h-[500px] gap-3 overflow-y-auto pr-2">
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

    <article class="glass-panel rounded-[28px] p-5">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-2xl font-extrabold">Очередь отчетов</h2>
        <span class="material-symbols-outlined text-primary">assignment</span>
      </div>
      <div v-if="dashboard.report_queue.length" class="dashboard-scroll grid max-h-[500px] gap-3 overflow-y-auto pr-2">
        <RouterLink v-for="report in dashboard.report_queue" :key="report.client_id" :to="{ path: '/participants', query: { client: report.client_id } }" class="rounded-2xl border border-white/10 bg-surface-container p-4 transition hover:border-primary/40 hover:bg-primary/10">
          <div class="flex items-center justify-between gap-3"><strong>{{ report.client_name }}</strong><span class="text-xs font-bold uppercase text-primary">Открыть отчёт</span></div>
          <p class="mt-2 text-sm leading-6 text-on-muted">Замер от {{ new Date(report.measured_on).toLocaleDateString('ru-RU') }} · вес {{ report.weight_kg }} кг<span v-if="report.waist_cm != null"> · талия {{ report.waist_cm }} см</span>.</p>
        </RouterLink>
      </div>
      <p v-else class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">Новых отчётов с замерами пока нет.</p>
    </article>
  </section>

  <section v-else-if="false" class="grid gap-5 lg:grid-cols-4">
    <article class="glass-panel relative overflow-hidden rounded-[28px] p-6 lg:col-span-2 lg:row-span-2">
      <div class="absolute right-0 top-0 h-44 w-44 rounded-full bg-primary/10 blur-3xl" />
      <div class="relative flex items-start justify-between gap-4">
        <div>
          <p class="text-sm font-semibold text-primary">Твой план на сегодня</p>
          <h2 class="mt-2 text-[30px] font-extrabold leading-9">
            {{ auth.user?.access_status === 'paid' ? 'Полный доступ открыт' : 'Открыты вводные уроки' }}
          </h2>
        </div>
        <div class="grid h-14 w-14 place-items-center rounded-full bg-surface-high">
          <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">favorite</span>
        </div>
      </div>

      <div class="relative mx-auto my-8 grid h-44 w-44 place-items-center rounded-full border-[10px] border-surface-highest">
        <div class="absolute inset-[-10px] rotate-45 rounded-full border-[10px] border-primary border-r-transparent border-t-transparent" />
        <div class="text-center">
          <strong class="block text-[36px] font-extrabold">65%</strong>
          <span class="text-xs font-semibold text-on-muted">Прогресс</span>
        </div>
      </div>

      <div class="grid gap-3">
        <RouterLink to="/workouts" class="flex items-center gap-4 rounded-2xl border border-white/5 bg-surface-container/70 p-4">
          <div class="grid h-11 w-11 place-items-center rounded-full bg-primary/15 text-primary">
            <span class="material-symbols-outlined">fitness_center</span>
          </div>
          <div class="flex-1">
            <h3 class="font-bold">Тренировка дня</h3>
            <p class="text-sm text-on-muted">Видео, таймер и отметка выполнения</p>
          </div>
          <span class="material-symbols-outlined text-primary">chevron_right</span>
        </RouterLink>
        <RouterLink to="/nutrition" class="flex items-center gap-4 rounded-2xl border border-white/5 bg-surface-container/70 p-4">
          <div class="grid h-11 w-11 place-items-center rounded-full bg-secondary/15 text-secondary">
            <span class="material-symbols-outlined">restaurant</span>
          </div>
          <div class="flex-1">
            <h3 class="font-bold">Дневник питания</h3>
            <p class="text-sm text-on-muted">КБЖУ, рецепты и ручные записи</p>
          </div>
          <span class="material-symbols-outlined text-primary">chevron_right</span>
        </RouterLink>
      </div>
    </article>

    <StatTile label="Ккал цель" value="1 760" icon="local_fire_department" />
    <StatTile label="Белок" value="105 г" icon="egg_alt" />
    <StatTile label="Уроки" value="68%" icon="play_circle" />
    <StatTile label="Замеры" value="-1.6 кг" icon="monitoring" />

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-2">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Динамика веса</h2>
        <RouterLink class="text-sm font-bold text-primary" to="/progress">Все замеры</RouterLink>
      </div>
      <ProgressChart :entries="progress" />
    </article>

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-2">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Уведомления</h2>
        <span class="material-symbols-outlined text-primary">notifications</span>
      </div>
      <ul class="grid gap-3">
        <li v-for="item in notifications" :key="item.id" class="rounded-2xl border border-white/5 bg-surface-container p-4">
          <strong class="block text-on-surface">{{ item.title }}</strong>
          <span class="mt-1 block text-sm leading-6 text-on-muted">{{ item.body }}</span>
        </li>
      </ul>
    </article>

    <article v-if="auth.user?.access_status !== 'paid'" class="glass-panel rounded-[28px] p-5 lg:col-span-4">
      <div class="mb-5 flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">доступ</p>
          <h2 class="mt-1 text-2xl font-extrabold">Анкета для открытия платного курса</h2>
        </div>
        <span class="material-symbols-outlined text-primary">assignment</span>
      </div>
      <form class="grid gap-4 lg:grid-cols-[1fr_320px_auto]" @submit.prevent="sendAccessRequest">
        <textarea v-model="questionnaire" class="min-h-28 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" />
        <input v-model="photoPath" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" placeholder="Путь к фото прогресса" />
        <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white" type="submit">
          Отправить
        </button>
      </form>
      <p v-if="requestSent" class="mt-3 text-sm font-bold text-primary">Заявка отправлена администратору.</p>
    </article>
  </section>

  <section v-else class="grid gap-5 lg:grid-cols-2">
    <article class="glass-panel rounded-[28px] p-6 lg:col-span-2">
      <div class="flex items-start gap-4">
        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/15 text-primary">
          <span class="material-symbols-outlined">favorite</span>
        </div>
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Личный кабинет</p>
          <h2 class="mt-2 max-w-3xl text-2xl font-extrabold leading-9 lg:text-3xl lg:leading-10">Добро пожаловать на Курс по снижению веса с индивидуальным сопровождением от Анастасии Лазаревой</h2>
        </div>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Мой доступ и уроки</h2>
        <span class="material-symbols-outlined text-primary">workspace_premium</span>
      </div>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
        <div class="rounded-2xl border border-primary/20 bg-primary/10 p-4">
          <span class="text-xs font-bold uppercase tracking-wide text-on-muted">Доступ к материалам</span>
          <strong class="mt-2 block text-lg text-primary">{{ auth.user?.access_status === 'paid' ? 'Платный доступ' : 'Бесплатный доступ' }}</strong>
        </div>
        <div class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <span class="text-xs font-bold uppercase tracking-wide text-on-muted">Пройдено тренировок</span>
          <strong class="mt-2 block text-3xl text-on-surface">{{ clientOverview.completed_workouts_count }}</strong>
          <p class="mt-1 text-sm text-on-muted">Отмечено во вкладке «Тренировки»</p>
        </div>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Уведомления</h2>
        <span class="material-symbols-outlined text-primary">notifications</span>
      </div>
      <ul v-if="notifications.length" class="dashboard-scroll grid max-h-[560px] gap-3 overflow-y-auto pr-2">
        <li v-for="item in notifications" :key="item.id" class="rounded-2xl border border-white/5 bg-surface-container p-4">
          <strong class="block text-on-surface">{{ item.title }}</strong>
          <span class="mt-1 block text-sm leading-6 text-on-muted">{{ item.body }}</span>
        </li>
      </ul>
      <p v-else class="rounded-2xl border border-white/5 bg-surface-container p-4 text-sm text-on-muted">Новых уведомлений пока нет.</p>
    </article>
  </section>

  <section v-if="false" class="grid gap-5 lg:grid-cols-2">
    <article class="glass-panel rounded-[28px] p-6 lg:col-span-2">
      <div class="flex items-start gap-4">
        <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary/15 text-primary">
          <span class="material-symbols-outlined">favorite</span>
        </div>
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Личный кабинет</p>
          <h2 class="mt-2 max-w-3xl text-2xl font-extrabold leading-9 lg:text-3xl lg:leading-10">Добро пожаловать на Курс по снижению веса с индивидуальным сопровождением от Анастасии Лазаревой</h2>
        </div>
      </div>
    </article>

    <article class="glass-panel rounded-[28px] p-5 lg:col-span-2">
      <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-extrabold">Уведомления</h2>
        <span class="material-symbols-outlined text-primary">notifications</span>
      </div>
      <ul v-if="notifications.length" class="grid gap-3">
        <li v-for="item in notifications" :key="item.id" class="rounded-2xl border border-white/5 bg-surface-container p-4">
          <strong class="block text-on-surface">{{ item.title }}</strong>
          <span class="mt-1 block text-sm leading-6 text-on-muted">{{ item.body }}</span>
        </li>
      </ul>
      <p v-else class="rounded-2xl border border-white/5 bg-surface-container p-4 text-sm text-on-muted">Новых уведомлений пока нет.</p>
    </article>
  </section>
</template>

<style scoped>
.dashboard-scroll {
  scrollbar-width: thin;
  scrollbar-color: rgb(203 155 255 / 0.75) transparent;
}

.dashboard-scroll::-webkit-scrollbar {
  width: 6px;
}

.dashboard-scroll::-webkit-scrollbar-track {
  margin-block: 8px;
  border-radius: 999px;
  background: rgb(255 255 255 / 0.04);
}

.dashboard-scroll::-webkit-scrollbar-thumb {
  min-height: 28px;
  border-radius: 999px;
  background: linear-gradient(180deg, rgb(220 178 255), rgb(165 94 234));
}

.dashboard-scroll::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(180deg, rgb(232 203 255), rgb(182 110 250));
}
</style>
