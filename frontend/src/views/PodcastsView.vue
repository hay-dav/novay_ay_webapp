<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';

const auth = useAuthStore();
const podcasts = ref([]);
const showCreateModal = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const coverPreview = ref('');
const coverInput = ref(null);
const audioInput = ref(null);
const form = ref({ title: '', description: '', cover: null, audio: null, access_level: 'paid' });
const isAdmin = computed(() => auth.user?.role === 'admin');
const canManagePodcasts = computed(() => ['admin', 'curator'].includes(auth.user?.role ?? ''));

async function load() {
    const { data } = await api.get('/podcasts');
    podcasts.value = data.data;
}
function selectCover(event) {
    const [cover] = event.target.files ?? [];
    form.value.cover = cover ?? null;
    if (coverPreview.value)
        URL.revokeObjectURL(coverPreview.value);
    coverPreview.value = cover ? URL.createObjectURL(cover) : '';
}
function selectAudio(event) {
    form.value.audio = event.target.files?.[0] ?? null;
}
function closeModal() {
    if (saving.value)
        return;
    showCreateModal.value = false;
    errorMessage.value = '';
    form.value = { title: '', description: '', cover: null, audio: null, access_level: 'paid' };
    if (coverPreview.value)
        URL.revokeObjectURL(coverPreview.value);
    coverPreview.value = '';
    if (coverInput.value)
        coverInput.value.value = '';
    if (audioInput.value)
        audioInput.value.value = '';
}
async function createPodcast() {
    if (!form.value.audio) {
        errorMessage.value = 'Выберите аудиофайл подкаста.';
        return;
    }
    saving.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('title', form.value.title);
    payload.append('description', form.value.description);
    payload.append('audio', form.value.audio);
    payload.append('access_level', form.value.access_level);
    if (form.value.cover)
        payload.append('cover', form.value.cover);
    try {
        await api.post('/podcasts', payload);
        saving.value = false;
        await load();
        closeModal();
    }
    catch (error) {
        errorMessage.value = error.response?.data?.errors
            ? Object.values(error.response.data.errors).flat().join(' ')
            : 'Не удалось добавить подкаст.';
    }
    finally {
        saving.value = false;
    }
}
async function deletePodcast(podcast) {
    if (!window.confirm(`Удалить подкаст «${podcast.title}»? Аудиофайл и обложка будут удалены без возможности восстановления.`))
        return;
    await api.delete(`/podcasts/${podcast.id}`);
    podcasts.value = podcasts.value.filter((item) => item.id !== podcast.id);
}
onMounted(load);
</script>

<template>
  <section class="grid gap-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Аудиоматериалы</p><h2 class="mt-2 text-[32px] font-extrabold leading-10">Подкасты</h2><p class="mt-2 text-on-muted">Слушайте полезные разговоры и рекомендации в удобное время.</p></div>
      <button v-if="isAdmin" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-3 text-sm font-extrabold text-white" type="button" @click="showCreateModal = true"><span class="material-symbols-outlined">add</span>Добавить подкаст</button>
    </header>

    <div v-if="podcasts.length" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <article v-for="podcast in podcasts" :key="podcast.id" class="glass-panel overflow-hidden rounded-[28px]">
        <img v-if="podcast.cover_path" class="aspect-video w-full object-cover" :src="podcast.cover_path" :alt="podcast.title" />
        <div v-else class="grid aspect-video place-items-center bg-gradient-to-br from-primary-container/45 to-surface-container text-primary"><span class="material-symbols-outlined text-6xl">headphones</span></div>
        <div class="p-5"><div class="flex items-center justify-between gap-3"><span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">{{ podcast.access_level === 'free' ? 'Бесплатно' : 'Полный доступ' }}</span><span class="material-symbols-outlined text-primary">graphic_eq</span></div><h3 class="mt-4 text-xl font-extrabold">{{ podcast.title }}</h3><p class="mt-2 text-sm leading-6 text-on-muted">{{ podcast.description }}</p><audio class="mt-5 w-full" :src="podcast.audio_url" controls controlsList="nodownload noplaybackrate" /><button v-if="canManagePodcasts" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-red-400/25 px-4 py-3 text-sm font-extrabold text-red-200 hover:bg-red-500/10" type="button" @click="deletePodcast(podcast)"><span class="material-symbols-outlined text-[20px]">delete</span>Удалить подкаст</button></div>
      </article>
    </div>
    <div v-else class="glass-panel grid min-h-64 place-items-center rounded-[28px] p-8 text-center"><div><span class="material-symbols-outlined text-[52px] text-primary">headphones</span><h3 class="mt-3 text-xl font-extrabold">Подкастов пока нет</h3><p class="mt-2 text-sm text-on-muted">Новые выпуски появятся здесь.</p></div></div>

    <Teleport to="body">
      <div v-if="showCreateModal" class="fixed inset-0 z-[120] flex items-end justify-center bg-black/70 p-0 backdrop-blur-sm sm:items-center sm:p-5" @mousedown.self="closeModal">
        <form class="glass-panel max-h-[92vh] w-full overflow-y-auto rounded-t-[28px] p-5 sm:max-w-2xl sm:rounded-[28px] sm:p-7" role="dialog" aria-modal="true" @submit.prevent="createPodcast">
          <div class="mb-6 flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Новая публикация</p><h3 class="mt-1 text-2xl font-extrabold">Добавить подкаст</h3></div><button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" :disabled="saving" @click="closeModal"><span class="material-symbols-outlined">close</span></button></div>
          <div class="grid gap-4"><label class="grid gap-2 text-sm font-bold text-on-muted">Название<input v-model.trim="form.title" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required /></label><label class="grid gap-2 text-sm font-bold text-on-muted">Описание<textarea v-model.trim="form.description" class="min-h-28 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required /></label><fieldset class="grid gap-2"><legend class="text-sm font-bold text-on-muted">Доступ</legend><div class="flex rounded-2xl border border-white/10 bg-surface-low p-1"><button v-for="option in [{ value: 'free', label: 'Бесплатный' }, { value: 'paid', label: 'Полный' }]" :key="option.value" class="flex-1 rounded-xl px-4 py-3 text-sm font-extrabold" :class="form.access_level === option.value ? 'bg-primary text-[#470382]' : 'text-on-muted'" type="button" @click="form.access_level = option.value">{{ option.label }}</button></div></fieldset><div class="grid gap-4 sm:grid-cols-2"><label class="grid cursor-pointer gap-3 rounded-2xl border border-dashed border-white/15 bg-surface-low p-4"><span class="text-sm font-bold">Обложка</span><img v-if="coverPreview" class="aspect-video rounded-xl object-cover" :src="coverPreview" alt="Предпросмотр" /><span v-else class="grid aspect-video place-items-center rounded-xl bg-surface-container"><span class="material-symbols-outlined text-4xl text-primary">add_photo_alternate</span></span><span class="truncate text-xs font-semibold text-primary">{{ form.cover?.name ?? 'Выбрать фото' }}</span><input ref="coverInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectCover" /></label><label class="grid cursor-pointer gap-3 rounded-2xl border border-dashed border-white/15 bg-surface-low p-4"><span class="text-sm font-bold">Аудиофайл</span><span class="grid aspect-video place-items-center rounded-xl bg-surface-container"><span class="material-symbols-outlined text-4xl text-primary">audio_file</span></span><span class="truncate text-xs font-semibold text-primary">{{ form.audio?.name ?? 'Выбрать аудио' }}</span><input ref="audioInput" class="sr-only" required type="file" accept="audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav" @change="selectAudio" /></label></div></div>
          <p v-if="errorMessage" class="mt-5 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200">{{ errorMessage }}</p><div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button class="rounded-2xl border border-white/10 px-5 py-3 text-sm font-bold text-on-muted" type="button" :disabled="saving" @click="closeModal">Отмена</button><button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-3 text-sm font-extrabold text-white disabled:opacity-60" type="submit" :disabled="saving">{{ saving ? 'Загружаем...' : 'Опубликовать' }}</button></div>
        </form>
      </div>
    </Teleport>
  </section>
</template>
