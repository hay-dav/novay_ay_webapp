<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useLiveStream } from '@/composables/useLiveStream';
import VideoWatermark from '@/components/VideoWatermark.vue';
const auth = useAuthStore();
const {
    activeStream,
    isHosting,
    liveModalOpen,
    liveLoading,
    liveError,
    connectionState,
    connectionQuality,
    playbackMuted,
    microphoneEnabled,
    cameraEnabled,
    recordingSaving,
    cameraFacingMode,
    cameraSwitching,
    viewerCount,
    viewerNames,
    viewerParticipants,
    localVideo,
    remoteVideo,
    conferenceParticipants,
    attachParticipantVideo,
    setLocalVideoElement,
    setRemoteVideoElement,
    attachLocalCamera,
    attachHostVideo,
    startActivePolling,
    startBroadcast,
    stopBroadcast,
    watchBroadcast,
    enablePlayback,
    toggleMicrophone,
    toggleCamera,
    switchCamera,
    selectRecordingStage,
    closeLiveModal,
} = useLiveStream();
// Keep the host labels below while sharing the same controls with participants.
const hostMicrophoneEnabled = microphoneEnabled;
const toggleHostMicrophone = toggleMicrophone;
const workouts = ref([]);
const activeFilter = ref('all');
const searchQuery = ref('');
const completed = ref({});
const showCreateModal = ref(false);
const showLiveStartModal = ref(false);
const saving = ref(false);
const uploadProgress = ref(0);
const deletingId = ref(null);
const editingWorkout = ref(null);
const editSaving = ref(false);
const editForm = ref({ title: '', description: '', access_level: 'paid' });
const liveStartForm = ref({ title: '', description: '', access_level: 'paid' });
const liveStartError = ref('');
const errorMessage = ref('');
const guestLink = ref('');
const guestLinkLoading = ref(false);
const guestLinkCopied = ref(false);
const participantsMenuOpen = ref(false);
const selectedParticipantId = ref(null);
const selectedParticipantVideo = ref(null);
const playingWorkout = ref(null);
const useMobileRecording = ref(false);
let mobileRecordingMediaQuery = null;
let guestLinkCopiedTimer = null;
const coverPreview = ref('');
const form = ref({
    title: '',
    description: '',
    cover: null,
    video: null,
    access_level: 'paid',
});
const MAX_VIDEO_BYTES = 2 * 1024 * 1024 * 1024;
const canManageWorkouts = computed(() => ['admin', 'curator', 'trainer'].includes(auth.user?.role ?? ''));
const canDownloadLiveRecordings = computed(() => ['admin', 'curator'].includes(auth.user?.role ?? ''));
const isAdmin = computed(() => auth.user?.role === 'admin');
const isClient = computed(() => auth.user?.role === 'client');
const selectedParticipant = computed(() => conferenceParticipants.value
    .find((participant) => participant.identity === selectedParticipantId.value) ?? null);
watch(selectedParticipant, async (participant) => {
    if (participant || !selectedParticipantId.value)
        return;
    selectedParticipantId.value = null;
    await nextTick();
    if (isAdmin.value)
        attachLocalCamera();
    else
        attachHostVideo();
});
const workoutFilters = [
    { value: 'all', label: 'Все', icon: 'apps' },
    { value: 'live', label: 'Эфир', icon: 'live_tv' },
    { value: 'video', label: 'Видео-тренировка', icon: 'fitness_center' },
    { value: 'podcast', label: 'Подкасты', icon: 'headphones' },
];
const filteredWorkouts = computed(() => {
    const search = searchQuery.value.trim().toLocaleLowerCase('ru-RU');

    return workouts.value.filter((workout) => (activeFilter.value === 'all' || workout.content_type === activeFilter.value)
        && (!search || workout.title.toLocaleLowerCase('ru-RU').includes(search)));
});
const emptyFilterMessage = computed(() => ({
    all: 'Тренировок пока нет. Новые видео и записи эфиров появятся здесь после публикации.',
    live: 'Записей эфиров пока нет. После завершения прямой трансляции запись появится здесь.',
    video: 'Видео-тренировок пока нет. Новые видео появятся здесь после публикации.',
    podcast: 'Подкасты пока не добавлены.',
}[activeFilter.value]));
async function load() {
    const { data } = await api.get('/workouts');
    workouts.value = data.data;
    completed.value = Object.fromEntries(workouts.value
        .filter((workout) => workout.is_completed)
        .map((workout) => [workout.id, true]));
}
async function complete(workout) {
    await api.post(`/workouts/${workout.id}/complete`);
    completed.value[workout.id] = true;
}
function openModal() {
    errorMessage.value = '';
    showCreateModal.value = true;
}
function closeModal() {
    if (saving.value)
        return;
    showCreateModal.value = false;
    resetForm();
}
function openEditModal(workout) {
    editingWorkout.value = workout;
    editForm.value = {
        title: workout.title,
        description: workout.description,
        access_level: workout.access_level,
    };
}
function closeEditModal() {
    if (!editSaving.value)
        editingWorkout.value = null;
}
async function saveWorkoutEdit() {
    if (!editingWorkout.value)
        return;
    editSaving.value = true;
    try {
        const { data } = await api.patch(`/workouts/${editingWorkout.value.id}`, editForm.value);
        const index = workouts.value.findIndex((workout) => workout.id === editingWorkout.value.id);
        if (index !== -1)
            workouts.value[index] = { ...workouts.value[index], ...data.data };
        editingWorkout.value = null;
    }
    finally {
        editSaving.value = false;
    }
}
function selectCover(event) {
    const input = event.target;
    const file = input.files?.[0] ?? null;
    form.value.cover = file;
    releaseCoverPreview();
    if (file)
        coverPreview.value = URL.createObjectURL(file);
}
function selectVideo(event) {
    const input = event.target;
    const file = input.files?.[0] ?? null;
    if (file && file.size > MAX_VIDEO_BYTES) {
        form.value.video = null;
        input.value = '';
        errorMessage.value = 'Размер видео превышает 2 ГБ. Выберите файл меньшего размера.';
        return;
    }
    form.value.video = file;
    errorMessage.value = '';
}
async function createWorkout() {
    if (!form.value.video) {
        errorMessage.value = 'Выберите видео тренировки.';
        return;
    }
    saving.value = true;
    uploadProgress.value = 0;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('title', form.value.title);
    payload.append('description', form.value.description);
    payload.append('video', form.value.video);
    payload.append('access_level', form.value.access_level);
    if (form.value.cover)
        payload.append('cover', form.value.cover);
    try {
        const { data } = await api.post('/workouts', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: (event) => {
                if (event.total)
                    uploadProgress.value = Math.round((event.loaded / event.total) * 100);
            },
        });
        try {
            await load();
        }
        catch {
            workouts.value = [
                { ...data.data, content_type: 'video' },
                ...workouts.value,
            ];
        }
        closeModal();
    }
    catch (error) {
        const validation = error.response?.data?.errors;
        if (validation) {
            errorMessage.value = Object.values(validation).flat().join(' ');
        }
        else if (error.response?.status === 413) {
            errorMessage.value = 'Размер видео превышает допустимые 2 ГБ.';
        }
        else if ([408, 504].includes(error.response?.status)) {
            errorMessage.value = 'Загрузка заняла слишком много времени. Проверьте интернет и повторите попытку.';
        }
        else if (!error.response) {
            errorMessage.value = 'Не удалось загрузить видео: соединение прервалось. Проверьте интернет и повторите попытку.';
        }
        else {
            errorMessage.value = 'Не удалось добавить тренировку. Повторите попытку.';
        }
    }
    finally {
        saving.value = false;
        uploadProgress.value = 0;
        if (!errorMessage.value)
            closeModal();
    }
}
function resetForm() {
    form.value = { title: '', description: '', cover: null, video: null, access_level: 'paid' };
    errorMessage.value = '';
    releaseCoverPreview();
}
async function deleteWorkout(workout) {
    if (!window.confirm(`Удалить тренировку «${workout.title}»? Видео и обложка будут удалены без возможности восстановления.`))
        return;
    deletingId.value = workout.id;
    try {
        await api.delete(`/workouts/${workout.id}`);
        workouts.value = workouts.value.filter((item) => item.id !== workout.id);
    }
    finally {
        deletingId.value = null;
    }
}
async function finishBroadcast() {
    const savedWorkout = await stopBroadcast();
    if (savedWorkout)
        await load();
}
async function createGuestLink() {
    if (!activeStream.value || guestLinkLoading.value)
        return;
    guestLinkLoading.value = true;
    liveError.value = '';
    try {
        const { data } = await api.post(`/live-streams/${activeStream.value.id}/guest-link`);
        guestLink.value = `${window.location.origin}${data.data.path}`;
        const copied = await copyGuestLink(guestLink.value);
        showGuestLinkCopyState(copied);
    }
    catch (error) {
        liveError.value = error.response?.data?.message ?? 'Не удалось создать гостевую ссылку.';
    }
    finally {
        guestLinkLoading.value = false;
    }
}

async function copyGuestLink(value) {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        }
        catch {
            // Fall back to selection-based copying for mobile browsers.
        }
    }

    const field = document.createElement('input');
    field.type = 'text';
    field.value = value;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.top = '0';
    field.style.left = '0';
    field.style.width = '1px';
    field.style.height = '1px';
    field.style.fontSize = '16px';
    field.style.opacity = '0.01';
    document.body.appendChild(field);
    field.focus({ preventScroll: true });
    field.select();
    field.setSelectionRange(0, value.length);
    const copied = document.execCommand('copy');
    field.remove();

    return copied;
}

function showGuestLinkCopyState(copied) {
    guestLinkCopied.value = copied;
    window.clearTimeout(guestLinkCopiedTimer);
    if (copied) {
        guestLinkCopiedTimer = window.setTimeout(() => {
            guestLinkCopied.value = false;
        }, 5000);
    }
}

async function copyDisplayedGuestLink() {
    if (!guestLink.value)
        return;
    const copied = await copyGuestLink(guestLink.value);
    showGuestLinkCopyState(copied);
}

async function startLiveBroadcast() {
    liveStartError.value = '';
    if (!liveStartForm.value.title.trim() || !liveStartForm.value.description.trim()) {
        liveStartError.value = 'Заполните название и описание записи.';
        return;
    }
    const started = await startBroadcast({
        title: liveStartForm.value.title.trim(),
        description: liveStartForm.value.description.trim(),
        access_level: liveStartForm.value.access_level,
    });
    if (started)
        showLiveStartModal.value = false;
    else
        liveStartError.value = liveError.value || 'Не удалось запустить эфир.';
}
function openLiveStartModal() {
    const date = new Intl.DateTimeFormat('ru-RU').format(new Date());
    liveStartForm.value = {
        title: `Запись эфира ${date}`,
        description: 'Запись прямой трансляции с тренером.',
        access_level: 'paid',
    };
    liveStartError.value = '';
    showLiveStartModal.value = true;
}
function closeLiveStartModal() {
    if (!liveLoading.value)
        showLiveStartModal.value = false;
}

async function reopenLiveBroadcast() {
    liveModalOpen.value = true;
    await nextTick();
    attachLocalCamera();
}

async function closeBroadcastView() {
    closeLiveModal();
}
function resumeLiveVideo(event) {
    const video = event.currentTarget;
    if (liveModalOpen.value && video?.paused)
        video.play().catch(() => undefined);
}
async function openParticipant(identity) {
    const participant = conferenceParticipants.value.find((item) => item.identity === identity);
    if (!participant)
        return;
    participantsMenuOpen.value = false;
    selectedParticipantId.value = identity;
    if (isAdmin.value)
        await selectRecordingStage(identity);
    await nextTick();
    attachParticipantVideo(identity, selectedParticipantVideo.value);
    if (isAdmin.value)
        attachLocalCamera();
    else
        attachHostVideo();
}
async function closeParticipant() {
    if (selectedParticipant.value && selectedParticipantVideo.value)
        selectedParticipant.value.track.detach(selectedParticipantVideo.value);
    selectedParticipantId.value = null;
    if (isAdmin.value)
        await selectRecordingStage();
    if (isAdmin.value) {
        await nextTick();
        attachLocalCamera();
    }
    else {
        await nextTick();
        attachHostVideo();
    }
}
async function openLiveBroadcast() {
    if (auth.user?.access_status !== 'paid') {
        liveError.value = 'Эфир доступен только платным пользователям';
        return;
    }

    await watchBroadcast();
}
function releaseCoverPreview() {
    if (coverPreview.value)
        URL.revokeObjectURL(coverPreview.value);
    coverPreview.value = '';
}
function updateRecordingVariant() {
    useMobileRecording.value = Boolean(mobileRecordingMediaQuery?.matches);
}
function workoutPlaybackUrl(workout) {
    return useMobileRecording.value && workout.mobile_video_path
        ? workout.mobile_video_path
        : workout.video_path;
}
function openWorkoutPlayback(workout) {
    playingWorkout.value = workout;
}
function closeWorkoutPlayback() {
    playingWorkout.value = null;
}
function handleKeydown(event) {
    if (event.key !== 'Escape')
        return;
    if (selectedParticipantId.value)
        closeParticipant();
    else if (playingWorkout.value)
        closeWorkoutPlayback();
    else if (showLiveStartModal.value)
        closeLiveStartModal();
    else if (showCreateModal.value)
        closeModal();
}
onMounted(() => {
    mobileRecordingMediaQuery = window.matchMedia('(max-width: 900px) and (pointer: coarse)');
    updateRecordingVariant();
    mobileRecordingMediaQuery.addEventListener?.('change', updateRecordingVariant);
    load();
    startActivePolling();
    window.addEventListener('keydown', handleKeydown);
});
onBeforeUnmount(() => {
    mobileRecordingMediaQuery?.removeEventListener?.('change', updateRecordingVariant);
    window.removeEventListener('keydown', handleKeydown);
    window.clearTimeout(guestLinkCopiedTimer);
    releaseCoverPreview();
});
</script>

<template>
  <section v-show="!liveModalOpen" class="grid gap-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Тренировки</p>
        <h2 class="mt-2 text-[32px] font-extrabold leading-10">Видео-тренировки</h2>
      </div>
      <div class="flex flex-col gap-3 sm:flex-row">
        <button
          v-if="isAdmin && !isHosting"
          class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl border border-red-400/30 bg-red-500/15 px-5 py-3 text-sm font-extrabold text-red-200"
          type="button"
          :disabled="liveLoading"
          @click="openLiveStartModal"
        >
          <span class="material-symbols-outlined text-[21px]">sensors</span>
          {{ liveLoading ? 'Подключение...' : 'Запустить эфир' }}
        </button>
        <button
          v-if="isAdmin && isHosting"
          class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-red-500 px-5 py-3 text-sm font-extrabold text-white"
          type="button"
          @click="reopenLiveBroadcast"
        >
          <span class="relative flex h-3 w-3"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-70"></span><span class="relative inline-flex h-3 w-3 rounded-full bg-white"></span></span>
          Эфир идет
        </button>
        <button
          v-if="isClient && activeStream"
          class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-red-500 px-5 py-3 text-sm font-extrabold text-white shadow-[0_10px_30px_rgba(239,68,68,0.22)]"
          type="button"
          :disabled="liveLoading"
          @click="openLiveBroadcast"
        >
          <span class="material-symbols-outlined text-[21px]">live_tv</span>
          {{ liveLoading ? 'Подключение...' : 'Просмотреть эфир' }}
        </button>
        <button
          v-if="canManageWorkouts"
          class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-3 text-sm font-extrabold text-white shadow-[0_10px_30px_rgba(109,56,168,0.25)]"
          type="button"
          @click="openModal"
        >
          <span class="material-symbols-outlined text-[21px]">add</span>
          Добавить тренировку
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Фильтры тренировок">
      <button
        v-for="filter in workoutFilters.filter((item) => item.value !== 'podcast')"
        :key="filter.value"
        class="inline-flex h-11 items-center gap-2 rounded-xl border px-4 text-sm font-extrabold transition focus:outline-none focus:ring-2 focus:ring-primary"
        :class="activeFilter === filter.value ? 'border-primary/40 bg-primary text-[#470382]' : 'border-white/10 bg-surface-container text-on-muted hover:bg-white/5 hover:text-on-surface'"
        type="button"
        role="tab"
        :aria-selected="activeFilter === filter.value"
        @click="activeFilter = filter.value"
      >
        <span class="material-symbols-outlined text-[20px]">{{ filter.icon }}</span>
        {{ filter.label }}
      </button>
    </div>

    <label class="relative block">
      <span class="sr-only">Поиск тренировки по названию</span>
      <span class="material-symbols-outlined pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-on-muted">search</span>
      <input
        v-model="searchQuery"
        class="min-h-12 w-full rounded-2xl border border-white/10 bg-surface-container py-3 pl-12 pr-4 text-sm text-on-surface outline-none placeholder:text-on-muted focus:border-primary/50 lg:max-w-md"
        type="search"
        placeholder="Поиск по названию тренировки"
      />
    </label>

    <div v-if="filteredWorkouts.length" class="grid gap-5 lg:grid-cols-2">
      <article v-for="workout in filteredWorkouts" :key="workout.id" class="glass-panel relative overflow-hidden rounded-[28px]">
        <div v-if="workout.video_path" class="group relative aspect-video overflow-hidden bg-black">
          <video
            class="pointer-events-none h-full w-full object-cover"
            :poster="workout.cover_path ?? undefined"
            :src="workout.video_path"
            muted
            playsinline
            preload="metadata"
            aria-hidden="true"
          />
          <button
            class="absolute inset-0 grid place-items-center bg-black/5 transition hover:bg-black/20 focus-visible:bg-black/20"
            type="button"
            :aria-label="`Воспроизвести тренировку ${workout.title}`"
            @click="openWorkoutPlayback(workout)"
          >
            <span class="grid h-14 w-14 place-items-center rounded-full bg-black/65 pl-1 text-white shadow-xl ring-1 ring-white/40 transition group-hover:scale-105">
              <span class="material-symbols-outlined text-[34px]">play_arrow</span>
            </span>
          </button>
        </div>
        <img
          v-else-if="workout.cover_path"
          class="aspect-video w-full bg-surface-low object-cover"
          :src="workout.cover_path"
          :alt="`Обложка тренировки ${workout.title}`"
        />
        <div v-else class="grid aspect-video w-full place-items-center bg-surface-container text-primary">
          <span class="material-symbols-outlined text-[56px]">fitness_center</span>
        </div>
        <VideoWatermark />

        <div class="p-5">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="flex flex-wrap gap-2">
                <span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">
                  {{ workout.access_level === 'free' ? 'Бесплатно' : 'Платный доступ' }}
                </span>
                <span v-if="workout.content_type === 'live'" class="rounded-full bg-red-500/15 px-3 py-1 text-xs font-bold text-red-200">Запись эфира</span>
              </div>
              <h3 class="mt-3 break-words text-xl font-extrabold">{{ workout.title }}</h3>
            </div>
            <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-surface-container text-primary">
              <span class="material-symbols-outlined">exercise</span>
            </div>
          </div>
          <p class="mt-3 text-sm leading-6 text-on-muted">{{ workout.description }}</p>
          <button
            v-if="!canManageWorkouts"
            class="mt-5 w-full rounded-2xl px-6 py-4 font-extrabold"
            :class="completed[workout.id] ? 'border border-primary/30 text-primary' : 'bg-gradient-to-br from-primary-container to-primary-strong text-white'"
            type="button"
            @click="complete(workout)"
          >
            {{ completed[workout.id] ? 'Выполнено' : 'Отметить тренировку' }}
          </button>
          <a
            v-if="canDownloadLiveRecordings && workout.content_type === 'live' && workout.download_url"
            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-primary/35 bg-primary/10 px-5 py-3 text-sm font-extrabold text-primary transition hover:bg-primary/20"
            :href="workout.download_url"
            download
          >
            <span class="material-symbols-outlined text-[20px]">download</span>
            Скачать эфир
          </a>
          <button
            v-if="canManageWorkouts && workout.content_type === 'live'"
            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-primary/35 bg-primary/10 px-5 py-3 text-sm font-extrabold text-primary transition hover:bg-primary/20"
            type="button"
            @click="openEditModal(workout)"
          >
            <span class="material-symbols-outlined text-[20px]">edit</span>
            Редактировать запись эфира
          </button>
          <button
            v-if="isAdmin"
            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-red-400/25 px-5 py-3 text-sm font-extrabold text-red-200 transition hover:bg-red-500/10 disabled:opacity-50"
            type="button"
            :disabled="deletingId === workout.id"
            @click="deleteWorkout(workout)"
          >
            <span class="material-symbols-outlined text-[20px]">delete</span>
            {{ deletingId === workout.id ? 'Удаление...' : 'Удалить тренировку' }}
          </button>
        </div>
      </article>
    </div>

    <div v-else class="glass-panel grid min-h-64 place-items-center rounded-[28px] p-8 text-center">
      <div>
        <span class="material-symbols-outlined text-[52px] text-primary">{{ activeFilter === 'podcast' ? 'headphones' : activeFilter === 'live' ? 'live_tv' : 'fitness_center' }}</span>
        <h3 class="mt-3 text-xl font-extrabold">{{ activeFilter === 'podcast' ? 'Подкастов пока нет' : activeFilter === 'live' ? 'Записей эфиров пока нет' : 'Видео-тренировок пока нет' }}</h3>
        <p class="mt-2 text-sm text-on-muted">{{ emptyFilterMessage }}</p>
      </div>
    </div>
  </section>

    <div v-if="liveModalOpen" class="live-stream-page fixed inset-0 z-[110] bg-black">
        <section class="relative h-full w-full overflow-hidden bg-black" aria-label="Прямой эфир">
          <header class="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-4">
            <div class="flex items-center gap-3">
              <span class="rounded-lg bg-red-500 px-3 py-1 text-xs font-extrabold uppercase text-white">Live</span>
              <div>
                <h3 class="font-extrabold text-white">Прямая трансляция</h3>
                <p class="text-xs text-on-muted">
                  {{ isAdmin
                    ? `${hostMicrophoneEnabled ? 'Камера и микрофон включены' : 'Камера включена, микрофон выключен'} · зрителей: ${viewerCount}`
                    : `В эфире ${activeStream?.host?.name ?? 'тренер'} · качество: ${connectionQuality === 'excellent' ? 'отличное' : connectionQuality === 'good' ? 'хорошее' : connectionQuality === 'poor' ? 'слабое' : 'определяется'}` }}
                </p>
              </div>
            </div>
            <div v-if="isAdmin || connectionState === 'connected'" class="live-participants-control relative">
              <button class="inline-flex h-10 items-center gap-2 rounded-xl border border-white/10 bg-black/35 px-3 text-xs font-extrabold text-on-surface backdrop-blur" type="button" :aria-expanded="participantsMenuOpen" @click.stop="participantsMenuOpen = !participantsMenuOpen">
                <span class="material-symbols-outlined text-[19px]">groups</span>
                {{ viewerCount }}
                <span class="material-symbols-outlined text-[18px]">{{ participantsMenuOpen ? 'expand_less' : 'expand_more' }}</span>
              </button>
              <div v-if="participantsMenuOpen" class="live-participants-menu absolute right-0 top-12 z-50 max-h-72 w-64 overflow-y-auto rounded-2xl border border-white/15 bg-[#211e25] p-2 shadow-2xl" @click.stop>
                <p v-if="!viewerParticipants.length" class="px-3 py-4 text-center text-xs text-on-muted">Пока никто не подключился</p>
                <button v-for="participant in viewerParticipants" :key="participant.identity" class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm hover:bg-white/10 disabled:cursor-default disabled:opacity-70" type="button" :disabled="!participant.cameraEnabled" @click="openParticipant(participant.identity)">
                  <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/15 font-extrabold text-primary">{{ participant.name.slice(0, 1).toUpperCase() }}</span>
                  <span class="min-w-0 flex-1 truncate">{{ participant.name }}</span>
                  <span class="material-symbols-outlined text-[18px]" :class="participant.microphoneEnabled ? 'text-emerald-300' : 'text-on-muted'" :title="participant.microphoneEnabled ? 'Микрофон включён' : 'Микрофон выключен'" :aria-label="participant.microphoneEnabled ? 'Микрофон включён' : 'Микрофон выключен'">{{ participant.microphoneEnabled ? 'mic' : 'mic_off' }}</span>
                  <span class="material-symbols-outlined text-[18px]" :class="participant.cameraEnabled ? 'text-emerald-300' : 'text-on-muted'">{{ participant.cameraEnabled ? 'videocam' : 'videocam_off' }}</span>
                </button>
              </div>
            </div>
            <button v-else class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть эфир" @click="closeBroadcastView">
              <span class="material-symbols-outlined">close</span>
            </button>
          </header>

          <div class="live-stage relative min-h-0 flex-1 bg-black sm:aspect-video sm:flex-none">
            <video
              v-if="isAdmin && !selectedParticipant"
              :ref="setLocalVideoElement"
              class="live-stage-video h-full w-full object-contain"
              :class="{ 'live-local-front-camera': cameraFacingMode === 'user' }"
              autoplay
              muted
              playsinline
              disablePictureInPicture
              disableRemotePlayback
              @pause="resumeLiveVideo"
              @contextmenu.prevent
            />
            <video
              v-else-if="selectedParticipant"
              ref="selectedParticipantVideo"
              class="live-stage-video h-full w-full object-contain"
              autoplay
              muted
              playsinline
              disablePictureInPicture
              disableRemotePlayback
              @pause="resumeLiveVideo"
              @contextmenu.prevent
            />
            <video v-else :ref="setRemoteVideoElement" class="live-stage-video h-full w-full object-contain" autoplay muted playsinline disablePictureInPicture disableRemotePlayback @pause="resumeLiveVideo" @contextmenu.prevent />
            <div v-if="selectedParticipant || (!isAdmin && cameraEnabled) || conferenceParticipants.length" class="live-conference-participants absolute bottom-4 left-4 flex max-w-[72%] gap-3 overflow-x-auto">
              <button
                v-if="isAdmin && selectedParticipant"
                class="live-conference-participant relative h-28 w-24 shrink-0 overflow-hidden rounded-xl border border-primary/70 bg-black text-left sm:h-32 sm:w-28"
                type="button"
                title="Вернуть видео тренера на основной экран"
                aria-label="Вернуть видео тренера на основной экран"
                @click="closeParticipant"
              >
                <video
                  :ref="setLocalVideoElement"
                  class="h-full w-full object-cover"
                  :class="{ 'live-local-front-camera': cameraFacingMode === 'user' }"
                  autoplay
                  muted
                  playsinline
                  disablePictureInPicture
                  disableRemotePlayback
                  @pause="resumeLiveVideo"
                />
                <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1.5 py-1 text-[10px] font-bold text-white">Вы</span>
              </button>
              <button
                v-if="!isAdmin && selectedParticipant"
                class="live-conference-participant relative h-28 w-24 shrink-0 overflow-hidden rounded-xl border border-primary/70 bg-black text-left sm:h-32 sm:w-28"
                type="button"
                title="Вернуть видео тренера на основной экран"
                aria-label="Вернуть видео тренера на основной экран"
                @click="closeParticipant"
              >
                <video :ref="setRemoteVideoElement" class="h-full w-full object-cover" autoplay muted playsinline disablePictureInPicture disableRemotePlayback @pause="resumeLiveVideo" />
                <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1.5 py-1 text-[10px] font-bold text-white">Тренер</span>
              </button>
              <div v-if="!isAdmin && cameraEnabled" class="live-conference-participant relative h-28 w-24 shrink-0 overflow-hidden rounded-xl border border-white/30 bg-black sm:h-32 sm:w-28">
                <video
                  :ref="setLocalVideoElement"
                  class="h-full w-full object-cover"
                  :class="{ 'live-local-front-camera': cameraFacingMode === 'user' }"
                  autoplay
                  muted
                  playsinline
                  disablePictureInPicture
                  disableRemotePlayback
                  @pause="resumeLiveVideo"
                />
                <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1.5 py-1 text-[10px] font-bold text-white">Вы</span>
              </div>
              <button v-for="participant in conferenceParticipants.filter((item) => item.identity !== selectedParticipantId).slice(0, isAdmin || !cameraEnabled ? 2 : 1)" :key="participant.identity" class="live-conference-participant relative h-28 w-24 shrink-0 overflow-hidden rounded-xl border border-white/30 bg-black text-left sm:h-32 sm:w-28" type="button" :aria-label="`Открыть видео ${participant.name}`" @click="openParticipant(participant.identity)">
                <video :ref="(element) => attachParticipantVideo(participant.identity, element)" class="h-full w-full object-cover" autoplay muted playsinline disablePictureInPicture disableRemotePlayback @pause="resumeLiveVideo" />
                <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1.5 py-1 text-[10px] font-bold text-white">{{ participant.name }}</span>
              </button>
            </div>
            <VideoWatermark />
            <div v-if="liveLoading" class="absolute inset-0 grid place-items-center bg-black/60 text-sm font-bold text-white">Подключение к эфиру...</div>
            <div v-else-if="!isAdmin && connectionState !== 'connected'" class="pointer-events-none absolute inset-0 grid place-items-center bg-black/55 p-6 text-center text-sm font-bold text-white">
              {{ connectionState === 'connecting' ? 'Настраиваем видео и звук...' : 'Ожидаем видеопоток администратора...' }}
            </div>
          </div>

          <footer class="flex shrink-0 flex-col gap-3 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:pb-4">
            <div v-if="liveError" class="rounded-xl border border-red-400/25 bg-[#311a23] px-4 py-3 text-sm font-bold text-red-100" role="alert">
              {{ liveError }}
            </div>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p class="text-sm text-on-muted">{{ isAdmin ? 'Один видеопоток отправляется на SFU-сервер и адаптивно распределяется зрителям. Эфир записывается.' : (connectionState === 'connected' ? 'Вы подключены к прямому эфиру в режиме просмотра.' : 'Устанавливается соединение...') }}</p>
            </div>
            <div v-if="!isAdmin" class="live-viewer-controls flex flex-wrap gap-2 sm:flex-row">
              <button class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10" type="button" :title="microphoneEnabled ? 'Выключить микрофон' : 'Включить микрофон'" :aria-label="microphoneEnabled ? 'Выключить микрофон' : 'Включить микрофон'" @click="toggleMicrophone">
                <span class="material-symbols-outlined text-[21px]">{{ microphoneEnabled ? 'mic' : 'mic_off' }}</span>
              </button>
              <button class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10" type="button" :title="cameraEnabled ? 'Выключить камеру' : 'Включить камеру'" :aria-label="cameraEnabled ? 'Выключить камеру' : 'Включить камеру'" @click="toggleCamera">
                <span class="material-symbols-outlined text-[21px]">{{ cameraEnabled ? 'videocam' : 'videocam_off' }}</span>
              </button>
              <button class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10 disabled:opacity-50" type="button" :disabled="cameraSwitching" title="Переключить камеру" aria-label="Переключить камеру" @click="switchCamera">
                <span class="material-symbols-outlined text-[21px]">{{ cameraSwitching ? 'progress_activity' : 'flip_camera_android' }}</span>
              </button>
              <button class="live-exit-action inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-red-500 px-4 py-2 text-sm font-extrabold text-white" type="button" @click="closeBroadcastView">
                <span class="material-symbols-outlined text-[20px]">logout</span>
                Выйти
              </button>
              <button v-if="playbackMuted" class="live-playback-action inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-extrabold text-[#470382]" type="button" @click="enablePlayback">
                <span class="material-symbols-outlined text-[20px]">volume_up</span>
                Включить звук
              </button>
            </div>
            <div v-if="isAdmin" class="live-host-controls flex flex-col gap-2 sm:flex-row">
              <button v-if="!guestLink" class="guest-link-action rounded-xl border border-primary/35 bg-primary/10 px-4 py-3 text-sm font-extrabold text-primary" type="button" :disabled="guestLinkLoading" @click="createGuestLink">
                {{ guestLinkLoading ? 'Создаём ссылку…' : 'Создать ссылку для гостей' }}
              </button>
              <button v-else class="guest-link-action inline-flex min-w-0 items-center gap-2 rounded-xl border border-primary/35 bg-primary/10 px-4 py-3 text-left text-sm font-extrabold text-primary" type="button" title="Скопировать гостевую ссылку" @click="copyDisplayedGuestLink">
                <span class="material-symbols-outlined shrink-0 text-[19px]">{{ guestLinkCopied ? 'check' : 'content_copy' }}</span>
                <span class="min-w-0 break-all">{{ guestLink }}</span>
              </button>
              <button
                class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10"
                type="button"
                :title="cameraEnabled ? 'Выключить камеру' : 'Включить камеру'"
                :aria-label="cameraEnabled ? 'Выключить камеру' : 'Включить камеру'"
                @click="toggleCamera"
              >
                <span class="material-symbols-outlined text-[21px]">{{ cameraEnabled ? 'videocam' : 'videocam_off' }}</span>
              </button>
              <button
                class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10"
                type="button"
                :title="hostMicrophoneEnabled ? 'Выключить микрофон администратора' : 'Включить микрофон администратора'"
                :aria-label="hostMicrophoneEnabled ? 'Выключить микрофон администратора' : 'Включить микрофон администратора'"
                @click="toggleHostMicrophone"
              >
                <span class="material-symbols-outlined text-[21px]">{{ hostMicrophoneEnabled ? 'mic' : 'mic_off' }}</span>
              </button>
              <button
                class="grid h-11 w-11 place-items-center rounded-xl border border-white/15 text-on-surface transition hover:bg-white/10 disabled:opacity-50"
                type="button"
                :disabled="cameraSwitching"
                :title="cameraFacingMode === 'environment' ? 'Переключить на фронтальную камеру' : 'Переключить на основную камеру'"
                :aria-label="cameraFacingMode === 'environment' ? 'Переключить на фронтальную камеру' : 'Переключить на основную камеру'"
                @click="switchCamera"
              >
                <span class="material-symbols-outlined text-[21px]">{{ cameraSwitching ? 'progress_activity' : 'flip_camera_android' }}</span>
              </button>
              <button class="rounded-xl bg-red-500 px-5 py-3 text-sm font-extrabold text-white disabled:cursor-wait disabled:opacity-60" type="button" :disabled="recordingSaving" @click="finishBroadcast">
                {{ recordingSaving ? 'Сохраняем запись...' : 'Завершить эфир' }}
              </button>
            </div>
            </div>
          </footer>
        </section>
      </div>

    <Teleport to="body">
      <div v-if="playingWorkout" class="fixed inset-0 z-[170] flex flex-col bg-black" role="dialog" aria-modal="true" :aria-label="`Видео тренировки ${playingWorkout.title}`" @mousedown.self="closeWorkoutPlayback">
        <header class="flex items-center justify-between gap-4 border-b border-white/10 bg-[#17131d] px-4 py-3 pt-[max(0.75rem,env(safe-area-inset-top))] text-white sm:px-6">
          <strong class="min-w-0 truncate text-sm sm:text-base">{{ playingWorkout.title }}</strong>
          <button class="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-white/20 bg-white/5" type="button" aria-label="Закрыть видео" @click="closeWorkoutPlayback">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>
        <div
          class="workout-playback-stage relative flex min-h-0 flex-1 items-center justify-center p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-6"
          :class="{ 'live-recording-playback': playingWorkout.content_type === 'live' }"
        >
          <video
            class="workout-playback-video h-auto max-h-full w-auto max-w-full bg-black object-contain"
            :src="workoutPlaybackUrl(playingWorkout)"
            controls
            autoplay
            playsinline
            controlsList="nodownload noplaybackrate noremoteplayback"
            disablePictureInPicture
            disableRemotePlayback
            @contextmenu.prevent
          />
          <VideoWatermark />
        </div>
      </div>

      <div v-if="liveError && !liveModalOpen" class="fixed bottom-24 right-5 z-[120] max-w-sm rounded-2xl border border-red-400/25 bg-[#311a23] p-4 text-sm font-bold text-red-100 shadow-2xl" role="alert">{{ liveError }}</div>

      <div v-if="showLiveStartModal" class="app-modal-backdrop z-[115] bg-black/65 backdrop-blur-sm" role="presentation" @mousedown.self="closeLiveStartModal">
        <form class="app-modal-panel glass-panel rounded-[28px] p-5 sm:max-w-xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="start-live-title" @submit.prevent="startLiveBroadcast">
          <div class="mb-6 flex items-center justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Запись эфира</p>
              <h3 id="start-live-title" class="mt-1 text-2xl font-extrabold">Начать эфир</h3>
            </div>
            <button class="grid h-10 w-10 place-items-center rounded-2xl border border-white/10 text-on-muted disabled:opacity-50" type="button" :disabled="liveLoading" aria-label="Закрыть" @click="closeLiveStartModal">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <div class="grid gap-4">
            <label class="grid gap-2 text-sm font-bold text-on-muted">Название
              <input v-model.trim="liveStartForm.title" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required maxlength="255" placeholder="Например, Эфир 05.08.2026" />
            </label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Описание
              <textarea v-model.trim="liveStartForm.description" class="min-h-28 resize-y rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required maxlength="5000" placeholder="Расскажите, о чём будет эфир" />
            </label>
            <fieldset class="grid gap-2">
              <legend class="text-sm font-bold text-on-muted">Доступ к записи</legend>
              <div class="flex rounded-2xl border border-white/10 bg-surface-low p-1">
                <button v-for="option in [{ value: 'free', label: 'Бесплатный' }, { value: 'paid', label: 'Платный' }]" :key="option.value" class="flex-1 rounded-xl px-4 py-3 text-sm font-extrabold transition" :class="liveStartForm.access_level === option.value ? 'bg-primary text-[#470382]' : 'text-on-muted hover:bg-white/5'" type="button" @click="liveStartForm.access_level = option.value">{{ option.label }}</button>
              </div>
            </fieldset>
            <p v-if="liveStartError" class="rounded-xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm font-bold text-red-100" role="alert">{{ liveStartError }}</p>
          </div>
          <button class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-4 font-extrabold text-white disabled:opacity-60" type="submit" :disabled="liveLoading">
            <span class="material-symbols-outlined">sensors</span>
            {{ liveLoading ? 'Запускаем эфир...' : 'Запустить эфир' }}
          </button>
        </form>
      </div>

      <div v-if="editingWorkout" class="app-modal-backdrop z-[115] bg-black/65 backdrop-blur-sm" role="presentation" @mousedown.self="closeEditModal">
        <form class="app-modal-panel glass-panel rounded-[28px] p-5 sm:max-w-xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="edit-live-title" @submit.prevent="saveWorkoutEdit">
          <div class="mb-6 flex items-center justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Запись эфира</p>
              <h3 id="edit-live-title" class="mt-1 text-2xl font-extrabold">Редактировать</h3>
            </div>
            <button class="grid h-10 w-10 place-items-center rounded-2xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" @click="closeEditModal">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <div class="grid gap-4">
            <label class="grid gap-2 text-sm font-bold text-on-muted">Название
              <input v-model.trim="editForm.title" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required maxlength="255" />
            </label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">Описание
              <textarea v-model.trim="editForm.description" class="min-h-28 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required />
            </label>
            <fieldset class="grid gap-2">
              <legend class="text-sm font-bold text-on-muted">Доступ к записи</legend>
              <div class="flex rounded-2xl border border-white/10 bg-surface-low p-1">
                <button v-for="option in [{ value: 'free', label: 'Бесплатный' }, { value: 'paid', label: 'Платный' }]" :key="option.value" class="flex-1 rounded-xl px-4 py-3 text-sm font-extrabold" :class="editForm.access_level === option.value ? 'bg-primary text-[#470382]' : 'text-on-muted'" type="button" @click="editForm.access_level = option.value">{{ option.label }}</button>
              </div>
            </fieldset>
          </div>
          <button class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-4 font-extrabold text-white disabled:opacity-60" type="submit" :disabled="editSaving">
            <span class="material-symbols-outlined">save</span>
            {{ editSaving ? 'Сохраняем...' : 'Сохранить изменения' }}
          </button>
        </form>
      </div>

      <div
        v-if="showCreateModal"
        class="app-modal-backdrop z-[100] bg-black/65 backdrop-blur-sm"
        role="presentation"
        @mousedown.self="closeModal"
      >
        <form
          class="app-modal-panel glass-panel rounded-[28px] p-5 sm:max-w-2xl sm:p-7"
          role="dialog"
          aria-modal="true"
          aria-labelledby="create-workout-title"
          @submit.prevent="createWorkout"
        >
          <div class="mb-6 flex items-center justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Новая публикация</p>
              <h3 id="create-workout-title" class="mt-1 text-2xl font-extrabold">Добавить тренировку</h3>
            </div>
            <button class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть" @click="closeModal">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <div class="grid gap-5">
            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Название тренировки
              <input v-model.trim="form.title" required maxlength="255" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/60" placeholder="Например, тренировка на всё тело" />
            </label>

            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Описание тренировки
              <textarea v-model.trim="form.description" required class="min-h-28 resize-y rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/60" placeholder="Расскажите, что понадобится и на какие мышцы рассчитана тренировка" />
            </label>

            <fieldset class="grid gap-2">
              <legend class="text-sm font-bold text-on-muted">Доступ к тренировке</legend>
              <div class="grid grid-cols-2 gap-2 rounded-2xl border border-white/10 bg-surface-low p-1.5">
                <button
                  v-for="option in [{ value: 'free', label: 'Бесплатный' }, { value: 'paid', label: 'Платный' }]"
                  :key="option.value"
                  class="rounded-xl px-4 py-3 text-sm font-extrabold transition"
                  :class="form.access_level === option.value ? 'bg-primary text-[#470382]' : 'text-on-muted hover:bg-white/5'"
                  type="button"
                  @click="form.access_level = option.value"
                >
                  {{ option.label }}
                </button>
              </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
              <label class="grid cursor-pointer gap-3 rounded-2xl border border-dashed border-white/15 bg-surface-low p-4 transition hover:border-primary/50">
                <span class="flex items-center gap-3 text-sm font-bold text-on-surface">
                  <span class="material-symbols-outlined text-primary">add_photo_alternate</span>
                  Добавить обложку
                </span>
                <img v-if="coverPreview" class="aspect-video w-full rounded-xl object-cover" :src="coverPreview" alt="Предпросмотр обложки" />
                <span v-else class="grid aspect-video place-items-center rounded-xl bg-surface-container text-xs text-on-muted">JPG, PNG или WEBP</span>
                <span class="truncate text-xs font-medium text-primary">{{ form.cover?.name ?? 'Выбрать с устройства' }}</span>
                <input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectCover" />
              </label>

              <label class="grid cursor-pointer content-start gap-3 rounded-2xl border border-dashed border-white/15 bg-surface-low p-4 transition hover:border-primary/50">
                <span class="flex items-center gap-3 text-sm font-bold text-on-surface">
                  <span class="material-symbols-outlined text-primary">video_file</span>
                  Видео тренировки
                </span>
                <span class="grid aspect-video place-items-center rounded-xl bg-surface-container">
                  <span class="material-symbols-outlined text-[42px] text-primary">upload</span>
                </span>
                <span class="truncate text-xs font-medium text-primary">{{ form.video?.name ?? 'Выбрать видео с устройства' }}</span>
                <span v-if="form.video" class="text-xs text-on-muted">{{ (form.video.size / 1024 / 1024).toFixed(1) }} МБ · лимит 2 ГБ</span>
                <span v-if="saving && uploadProgress" class="text-xs font-bold text-primary">Загрузка видео: {{ uploadProgress }}%</span>
                <input class="sr-only" required type="file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" @change="selectVideo" />
              </label>
            </div>
          </div>

          <p v-if="errorMessage" class="mt-5 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200" role="alert">
            {{ errorMessage }}
          </p>

          <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button class="rounded-2xl border border-white/10 px-5 py-3 text-sm font-bold text-on-muted" type="button" :disabled="saving" @click="closeModal">Отмена</button>
            <button class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-3 text-sm font-extrabold text-white disabled:cursor-wait disabled:opacity-60" type="submit" :disabled="saving">
              <span class="material-symbols-outlined text-[20px]">{{ saving ? 'progress_activity' : 'publish' }}</span>
              {{ saving ? `Загрузка${uploadProgress ? ` ${uploadProgress}%` : '...'}` : 'Опубликовать тренировку' }}
            </button>
          </div>
        </form>
      </div>
    </Teleport>
</template>
