<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const LiveViewer = defineAsyncComponent(() => import('@/views/WorkoutsView.vue'));
const selectedLive = ref(null);
const joinError = ref('');
function joinLive() {
    if (!activeLive.value || selectedLive.value) return;
    joinError.value = '';
    selectedLive.value = activeLive.value;
}
function liveFailed(message) { selectedLive.value = null; joinError.value = message; }
const clientOverview = ref({ completed_workouts_count: 0 });
const news = ref([]);
const chats = ref([]);
const liveStreams = ref([]);
const dashboard = ref({ clients: 0, clients_list: [], report_queue: [] });
let liveTimer;

const greetingName = computed(() => String(auth.user?.name ?? '').trim().split(/\s+/)[0] || 'участница');
const accessEndLabel = computed(() => {
    const value = auth.user?.access_ends_at;
    if (!value || Number.isNaN(new Date(value).getTime())) return '';
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Saratov',
    }).format(new Date(value));
});
const canRenewAccess = computed(() => !['newcomer', 'dropped_out'].includes(auth.user?.staff_status ?? ''));
const activeLive = computed(() => liveStreams.value.find(Boolean) ?? null);
const activeLiveRoute = computed(() => activeLive.value?.section === 'experts' ? '/expert-lives' : '/workouts');
const activeLiveLabel = computed(() => activeLive.value?.section === 'experts' ? 'Эфир с экспертом уже начался' : 'Сейчас идет тренировка');
const unreadChatCount = computed(() => chats.value.reduce((total, chat) => total + Number(chat.unread_count ?? 0), 0));

function messageText(value) {
    return typeof value === 'string' && value.trim() ? value.trim() : 'Новое сообщение';
}
function messageTime(value) {
    if (!value || Number.isNaN(new Date(value).getTime())) return '';
    return new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Saratov' }).format(new Date(value));
}
function newsDate(value) {
    if (!value || Number.isNaN(new Date(value).getTime())) return '';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', timeZone: 'Europe/Saratov' }).format(new Date(value));
}
function normalizeNews(payload) {
    const entries = Array.isArray(payload) ? payload : payload?.data ?? [];
    return entries.slice(0, 2).map((item) => ({
        id: item.id, title: item.title ?? 'Новость', body: item.excerpt || item.body || '',
        published_at: item.published_at ?? item.created_at, label: item.label ?? item.category?.name ?? '',
        image: item.preview_image_path ?? item.cover_image_path ?? '',
    }));
}
function normalizeChats(peers, general, important) {
    const entries = [
        ...(general ? [{ ...general, id: `room-${general.slug}`, name: general.name ?? 'Общий чат', query: { room: 'general' } }] : []),
        ...(important ? [{ ...important, id: `room-${important.slug}`, name: important.name ?? 'Важная информация', query: { room: 'important' } }] : []),
        ...peers,
    ].filter((item) => item?.last_message_at || item?.last_message);
    return entries.map((item) => ({
        id: item.id, name: item.name ?? 'Чат', body: messageText(item.last_message),
        last_message_at: item.last_message_at, unread_count: item.unread_count ?? 0, avatar_path: item.avatar_path ?? '',
        query: item.query ?? { peer: item.id },
    })).sort((first, second) => new Date(second.last_message_at ?? 0) - new Date(first.last_message_at ?? 0)).slice(0, 2);
}
async function loadClientDashboard() {
    const [summaryResponse, peersResponse, generalResponse, importantResponse, newsResponse] = await Promise.all([
        api.get('/workouts/summary').catch(() => ({ data: { data: { completed_workouts_count: 0 } } })),
        api.get('/chat/peers').catch(() => ({ data: { data: [] } })),
        api.get('/chat/general').catch(() => ({ data: { data: null } })),
        api.get('/chat/important').catch(() => ({ data: { data: null } })),
        api.get('/article-lessons', { params: { section: 'news', limit: 2 } }).catch(() => ({ data: { data: [] } })),
    ]);
    clientOverview.value = summaryResponse.data.data ?? { completed_workouts_count: 0 };
    chats.value = normalizeChats(peersResponse.data.data ?? [], generalResponse.data.data, importantResponse.data.data);
    news.value = normalizeNews(newsResponse.data);
}
async function refreshLiveStreams() {
    const [workoutsResponse, expertsResponse] = await Promise.all([
        api.get('/live-streams/active', { params: { section: 'workouts' } }).catch(() => ({ data: { data: null } })),
        api.get('/live-streams/active', { params: { section: 'experts' } }).catch(() => ({ data: { data: null } })),
    ]);
    liveStreams.value = [workoutsResponse.data.data, expertsResponse.data.data];
}
async function loadTrainerDashboard() {
    const { data } = await api.get('/trainer/dashboard');
    dashboard.value = data.data;
}
onMounted(async () => {
    if (auth.isTrainer) {
        await loadTrainerDashboard();
        return;
    }
    await Promise.all([loadClientDashboard(), refreshLiveStreams()]);
    liveTimer = window.setInterval(() => refreshLiveStreams().catch(() => undefined), 5000);
});
onBeforeUnmount(() => window.clearInterval(liveTimer));
</script>

<template>
  <section v-if="auth.isTrainer" class="grid gap-5 lg:grid-cols-2">
    <article class="glass-panel rounded-[28px] p-5 shadow-[0_14px_38px_rgba(109,56,168,0.16)]">
      <div class="mb-5 flex items-center justify-between">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">сопровождение</p>
          <div class="mt-1 flex flex-wrap items-center gap-3"><h2 class="text-2xl font-extrabold">Клиенты</h2><span class="rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary">{{ dashboard.clients }} участниц</span></div>
        </div>
        <RouterLink to="/participants" class="rounded-2xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]">Открыть</RouterLink>
      </div>
      <div class="dashboard-scroll grid max-h-[500px] gap-3 overflow-y-auto pr-2">
        <article v-for="client in dashboard.clients_list" :key="client.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
          <div class="flex items-center gap-3"><div class="grid h-11 w-11 place-items-center rounded-full bg-primary/15 text-primary"><span class="material-symbols-outlined">person</span></div><div><strong class="block">{{ client.name }}</strong><span class="text-sm text-on-muted">{{ client.goal }}</span></div></div>
        </article>
      </div>
    </article>
    <article class="glass-panel rounded-[28px] p-5">
      <div class="mb-5 flex items-center justify-between"><h2 class="text-2xl font-extrabold">Очередь отчетов</h2><span class="material-symbols-outlined text-primary">assignment</span></div>
      <div v-if="dashboard.report_queue.length" class="dashboard-scroll grid max-h-[500px] gap-3 overflow-y-auto pr-2">
        <RouterLink v-for="report in dashboard.report_queue" :key="report.client_id" :to="{ path: '/participants', query: { client: report.client_id } }" class="rounded-2xl border border-white/10 bg-surface-container p-4 transition hover:border-primary/40 hover:bg-primary/10">
          <div class="flex items-center justify-between gap-3"><strong>{{ report.client_name }}</strong><span class="text-xs font-bold uppercase text-primary">Открыть отчёт</span></div>
          <p class="mt-2 text-sm leading-6 text-on-muted">Замер от {{ new Date(report.measured_on).toLocaleDateString('ru-RU') }} · вес {{ report.weight_kg }} кг<span v-if="report.waist_cm != null"> · талия {{ report.waist_cm }} см</span>.</p>
        </RouterLink>
      </div>
      <p v-else class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">Новых отчётов с замерами пока нет.</p>
    </article>
  </section>

  <section v-else class="dashboard-home grid min-w-0 gap-5">
    <button v-if="activeLive" type="button" class="live-join-banner" :disabled="Boolean(selectedLive)" @click="joinLive">
      <span class="live-join-icon material-symbols-outlined" aria-hidden="true">sensors</span>
      <span class="live-join-copy"><span class="live-join-label">{{ activeLiveLabel }}</span><strong>Присоединяйся!</strong><span>Забота о теле — это вклад в себя</span></span>
      <span class="live-join-action">{{ selectedLive ? 'Подключение…' : activeLive.section === 'experts' ? 'Перейти к эфиру' : 'Перейти к тренировке' }}<span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></span>
    </button>
    <p v-if="joinError" role="alert" class="rounded-xl bg-red-500/10 p-3 text-sm text-red-200">{{ joinError }}</p>
    <LiveViewer v-if="selectedLive" :key="selectedLive.id" live-only :section="selectedLive.section || 'workouts'" :initial-stream="selectedLive" @live-closed="selectedLive = null" @live-failed="liveFailed" />

    <div class="quick-links grid grid-cols-3 gap-2 lg:gap-5">
      <RouterLink to="/workouts" class="quick-link"><span class="material-symbols-outlined quick-link__icon">fitness_center</span><strong>Тренировки</strong><small>Записи онлайн тренировок</small></RouterLink>
      <RouterLink to="/progress" class="quick-link"><span class="material-symbols-outlined quick-link__icon">monitoring</span><strong>Мой прогресс</strong><small>Результаты и замеры</small></RouterLink>
      <RouterLink to="/lessons" class="quick-link"><span class="material-symbols-outlined quick-link__icon">article</span><strong>Уроки</strong><small>Материалы курса</small></RouterLink>
    </div>
    <div class="dashboard-panels grid gap-5 xl:grid-cols-2">
      <article class="glass-panel order-3 rounded-[28px] p-5 xl:order-3 xl:col-span-2">
        <div class="mb-5 flex items-center justify-between"><h2 class="text-xl font-extrabold">Мой доступ и уроки</h2><span class="material-symbols-outlined text-primary">workspace_premium</span></div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
          <div class="rounded-2xl bg-[#342c3e] p-4"><span class="text-xs font-bold uppercase tracking-wide text-on-muted">Доступ к материалам</span><strong class="mt-2 block text-lg text-primary"><template v-if="auth.user?.access_status === 'paid' && accessEndLabel">Доступ открыт до <span class="text-white">{{ accessEndLabel }}</span></template><template v-else>{{ auth.user?.access_status === 'paid' ? 'Доступ открыт' : 'Доступ ограничен' }}</template></strong><div class="mt-4 flex flex-col gap-2 sm:flex-row"><RouterLink to="/tariffs" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-extrabold text-[#470382]">Выбрать тариф</RouterLink><a v-if="canRenewAccess" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-primary/35 px-4 py-2.5 text-sm font-extrabold text-primary" href="https://lazareva-secret.tb.ru/prodlenie" target="_blank" rel="noreferrer">Продлить</a></div></div>
          <div class="rounded-2xl bg-surface-container p-4"><span class="text-xs font-bold uppercase tracking-wide text-on-muted">Пройдено тренировок</span><strong class="mt-2 block text-3xl">{{ clientOverview.completed_workouts_count }}</strong><p class="mt-1 text-sm text-on-muted">Отмечено во вкладке «Тренировки»</p></div>
        </div>
      </article>
      <article class="news-panel glass-panel order-1 rounded-[28px] p-5 xl:order-2"><div class="mb-5 flex items-center gap-3"><span class="material-symbols-outlined text-primary">campaign</span><h2 class="text-xl font-extrabold">Новости</h2></div><div v-if="news.length" class="news-list grid divide-y divide-white/10"><RouterLink v-for="item in news" :key="item.id" :to="{ path: '/news', query: { news: item.id } }" class="news-item flex gap-3 py-4 first:pt-0 last:pb-0"><img v-if="item.image" :src="item.image" :alt="item.title" class="h-20 w-20 shrink-0 rounded-xl object-cover"><span v-else class="grid h-20 w-20 shrink-0 place-items-center rounded-xl bg-primary/15 text-primary"><span class="material-symbols-outlined">image</span></span><span class="min-w-0"><span v-if="item.label" class="text-xs font-bold uppercase tracking-wide text-primary">{{ item.label }}</span><h3 class="mt-1 text-base font-extrabold">{{ item.title }}</h3><p v-if="item.body" class="mt-1 line-clamp-2 text-sm leading-5 text-on-muted">{{ item.body }}</p><time class="mt-2 block text-xs text-on-muted">{{ newsDate(item.published_at) }}</time></span></RouterLink></div><p v-else class="rounded-2xl bg-surface-container p-4 text-sm leading-6 text-on-muted">Новых публикаций пока нет.</p></article>
    </div>
  </section>
</template>

<style scoped>
.live-banner { background: linear-gradient(105deg, rgb(195 133 235), rgb(145 74 196)); box-shadow: 0 14px 38px rgb(142 78 189 / 0.24); }
.quick-link { display: flex; min-width: 0; min-height: 180px; flex-direction: column; gap: .55rem; overflow-wrap: anywhere; border-radius: 24px; background: rgb(255 255 255 / .035); padding: 1rem; transition: background .2s ease, transform .2s ease; }
.quick-link:hover { background: rgb(203 155 255 / .12); transform: translateY(-2px); }
.quick-link__icon { display: grid; width: 2.75rem; height: 2.75rem; place-items: center; border-radius: 1rem; background: rgb(203 155 255 / .16); color: rgb(218 175 255); font-size: 1.5rem; }
.quick-link strong { margin-top: auto; font-size: 1rem; }
.quick-link small, .chat-preview small { color: rgb(190 182 202); font-size: .875rem; line-height: 1.35; }
.chat-preview { display: flex; min-width: 0; min-height: 68px; align-items: center; gap: .75rem; border-radius: 1rem; background: rgb(255 255 255 / .035); padding: .75rem; transition: background .2s ease; }
.chat-preview:hover { background: rgb(203 155 255 / .1); }
@media (max-width: 639px) { .mobile-information-pair { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); align-items: stretch; gap: .75rem; } .news-panel, .chat-panel { min-width: 0; } .chat-panel { padding: .75rem; } .chat-panel > :first-child { margin-bottom: .75rem; gap: .25rem; } .chat-panel > :first-child > a { display: none; } .chat-list { grid-template-columns: minmax(0, 1fr); gap: .5rem; } .chat-preview { min-height: 3.5rem; padding: .45rem; gap: 0; } .chat-preview > :first-child, .chat-preview time { display: none; } .chat-preview small { font-size: .68rem; } }
@media (max-width: 639px) { .quick-link { min-height: 7rem; border-radius: 16px; padding: .6rem; gap: .25rem; } .quick-link__icon { width: 2.1rem; height: 2.1rem; border-radius: .75rem; font-size: 1.2rem; } .quick-link strong { font-size: .74rem; line-height: 1.15; } .quick-link small { font-size: .62rem; line-height: 1.2; } .news-panel { min-height: 21rem; } .news-item { min-width: 0; flex-direction: column; gap: .5rem; } .news-item > img, .news-item > span:first-child { width: 100%; height: 5rem; } .news-item h3 { overflow-wrap: anywhere; font-size: .875rem; } .news-panel { padding: .75rem; } }
@media (prefers-reduced-motion: reduce) { .quick-link, .chat-preview { transition: none; } .quick-link:hover { transform: none; } }
</style>
