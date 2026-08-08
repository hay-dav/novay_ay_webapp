<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
import VideoWatermark from '@/components/VideoWatermark.vue';

const auth = useAuthStore();
const lessons = ref([]);
const selectedLesson = ref(null);
const createModalOpen = ref(false);
const saving = ref(false);
const uploadProgress = ref(0);
const errorMessage = ref('');
const editingLesson = ref(null);
const form = ref({ title: '', excerpt: '', access_level: 'paid', blocks: [{ type: 'text', content: '' }] });
const canCreateLessons = computed(() => ['admin', 'curator'].includes(auth.user?.role ?? ''));

async function loadLessons() {
    const { data } = await api.get('/article-lessons');
    lessons.value = data.data;
}
function addTextBlock() {
    form.value.blocks.push({ type: 'text', content: '' });
}
function addImageBlock() {
    form.value.blocks.push({ type: 'image', file: null, preview: '' });
}
function addVideoBlock() {
    form.value.blocks.push({ type: 'video', file: null, preview: '' });
}
function selectBlockImage(event, block) {
    const [image] = event.target.files ?? [];
    block.file = image ?? null;
    block.replaceImage = Boolean(image);
    if (block.preview)
        URL.revokeObjectURL(block.preview);
    block.preview = image ? URL.createObjectURL(image) : '';
}
function selectBlockVideo(event, block) {
    const [video] = event.target.files ?? [];
    block.file = video ?? null;
    block.replaceVideo = Boolean(video);
    if (block.preview?.startsWith('blob:'))
        URL.revokeObjectURL(block.preview);
    block.preview = video ? URL.createObjectURL(video) : '';
}
function removeBlock(index) {
    const [block] = form.value.blocks.splice(index, 1);
    if (block?.preview)
        URL.revokeObjectURL(block.preview);
}
function resetForm() {
    form.value.blocks.forEach((block) => block.preview && URL.revokeObjectURL(block.preview));
    form.value = { title: '', excerpt: '', access_level: 'paid', blocks: [{ type: 'text', content: '' }] };
    editingLesson.value = null;
}
function closeCreateModal() {
    if (saving.value)
        return;
    createModalOpen.value = false;
    errorMessage.value = '';
    resetForm();
}
async function createLesson() {
    if (!form.value.blocks.length) {
        errorMessage.value = 'Добавьте хотя бы один текстовый блок или фото.';
        return;
    }
    const missingMedia = form.value.blocks.find((block) => ['image', 'video'].includes(block.type) && !block.file && !block.id);
    if (missingMedia) {
        errorMessage.value = missingMedia.type === 'video' ? 'Выберите видеофайл.' : 'Выберите изображение.';
        return;
    }
    saving.value = true;
    uploadProgress.value = 0;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('title', form.value.title);
    payload.append('excerpt', form.value.excerpt);
    payload.append('access_level', form.value.access_level);
    payload.append('blocks', JSON.stringify(form.value.blocks.map((block) => ({ id: block.id, type: block.type, content: block.content ?? '', replace_image: Boolean(block.replaceImage), replace_video: Boolean(block.replaceVideo) }))));
    form.value.blocks.forEach((block, index) => {
        if (block.type === 'image' && block.file)
            payload.append(`images[${index}]`, block.file);
        if (block.type === 'video' && block.file)
            payload.append(`videos[${index}]`, block.file);
    });
    try {
        if (editingLesson.value)
            payload.append('_method', 'PATCH');
        const endpoint = editingLesson.value ? `/article-lessons/${editingLesson.value.id}` : '/article-lessons';
        const { data } = await api.post(endpoint, payload, {
            // Large videos first reach the server and are then copied to private S3.
            // A finite timeout prevents the form from remaining in the saving state forever.
            timeout: 1_800_000,
            onUploadProgress: (event) => {
                if (event.total)
                    uploadProgress.value = Math.round((event.loaded / event.total) * 100);
            },
        });
        const index = lessons.value.findIndex((lesson) => lesson.id === data.data.id);
        if (index >= 0)
            lessons.value.splice(index, 1, data.data);
        else
            lessons.value.unshift(data.data);
        saving.value = false;
        closeCreateModal();
    }
    catch (error) {
        if (error.response?.data?.errors)
            errorMessage.value = Object.values(error.response.data.errors).flat().join(' ');
        else if (error.response?.status === 400)
            errorMessage.value = 'Сервер не смог принять файл. Обновите страницу и повторите загрузку.';
        else if (error.response?.status === 413)
            errorMessage.value = 'Размер файла превышает допустимые 2 ГБ.';
        else if (error.code === 'ECONNABORTED')
            errorMessage.value = 'Загрузка заняла более 30 минут. Проверьте соединение и попробуйте ещё раз.';
        else if (!error.response)
            errorMessage.value = 'Соединение с сервером прервалось. Проверьте интернет и попробуйте ещё раз.';
        else
            errorMessage.value = error.response?.data?.message ?? 'Не удалось опубликовать урок.';
    }
    finally {
        saving.value = false;
        uploadProgress.value = 0;
    }
}
function openEdit(lesson) {
    editingLesson.value = lesson;
    form.value = {
        title: lesson.title,
        excerpt: lesson.excerpt,
        access_level: lesson.access_level ?? 'paid',
        blocks: lesson.blocks?.length
            ? lesson.blocks.map((block) => ({ id: block.id, type: block.type, content: block.content ?? '', file: null, preview: block.image_path ?? block.video_path ?? '' }))
            : [{ type: 'text', content: lesson.body ?? '' }],
    };
    selectedLesson.value = null;
    errorMessage.value = '';
    createModalOpen.value = true;
}
async function deleteLesson(lesson) {
    if (!window.confirm(`Удалить урок «${lesson.title}»? Все текстовые блоки и фотографии будут удалены без возможности восстановления.`))
        return;
    await api.delete(`/article-lessons/${lesson.id}`);
    lessons.value = lessons.value.filter((item) => item.id !== lesson.id);
    selectedLesson.value = null;
}
function onKeydown(event) {
    if (event.key === 'Escape') {
        selectedLesson.value = null;
        closeCreateModal();
    }
}
onMounted(() => {
    loadLessons();
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    resetForm();
});
</script>

<template>
  <section class="grid gap-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">Библиотека знаний</p><h2 class="mt-2 text-[32px] font-extrabold leading-10">Уроки</h2><p class="mt-2 max-w-2xl text-on-muted">Полезные статьи и материалы курса — всё важное в одном месте.</p></div>
      <button v-if="canCreateLessons" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-3 text-sm font-extrabold text-white" type="button" @click="createModalOpen = true"><span class="material-symbols-outlined">add</span>Создать урок</button>
    </header>

    <div v-if="lessons.length" class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
      <button v-for="lesson in lessons" :key="lesson.id" class="glass-panel group overflow-hidden rounded-[28px] text-left transition hover:-translate-y-1 hover:border-primary/35 focus:outline-none focus:ring-2 focus:ring-primary" type="button" @click="selectedLesson = lesson">
        <img v-if="lesson.preview_image_path" class="aspect-[16/9] w-full object-cover" :src="lesson.preview_image_path" :alt="lesson.title" />
        <div v-else class="grid aspect-[16/9] place-items-center bg-gradient-to-br from-primary-container/45 to-surface-container text-primary"><span class="material-symbols-outlined text-5xl">article</span></div>
        <div class="p-5"><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Урок</p><h3 class="mt-2 line-clamp-2 text-xl font-extrabold leading-7">{{ lesson.title }}</h3><p class="mt-3 line-clamp-3 text-sm leading-6 text-on-muted">{{ lesson.excerpt }}</p><span class="mt-5 inline-flex items-center gap-2 text-sm font-extrabold text-primary">Читать <span class="material-symbols-outlined text-[18px] transition group-hover:translate-x-1">arrow_forward</span></span></div>
      </button>
    </div>
    <div v-else class="glass-panel grid min-h-64 place-items-center rounded-[28px] p-8 text-center"><div><span class="material-symbols-outlined text-[52px] text-primary">menu_book</span><h3 class="mt-3 text-xl font-extrabold">Уроков пока нет</h3><p class="mt-2 text-sm text-on-muted">Новые материалы появятся здесь после публикации.</p></div></div>

    <Teleport to="body">
      <div v-if="selectedLesson" class="app-modal-backdrop z-[110] bg-black/70 backdrop-blur-sm" @mousedown.self="selectedLesson = null">
        <article class="app-modal-panel glass-panel rounded-[28px] p-5 sm:max-w-3xl sm:p-8" role="dialog" aria-modal="true" :aria-label="selectedLesson.title">
          <div class="mb-6 flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Урок</p><h3 class="mt-2 text-2xl font-extrabold leading-8 sm:text-3xl">{{ selectedLesson.title }}</h3></div><div class="flex shrink-0 gap-2"><button v-if="canCreateLessons" class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-primary hover:bg-primary/10" type="button" aria-label="Редактировать урок" @click="openEdit(selectedLesson)"><span class="material-symbols-outlined">edit</span></button><button v-if="canCreateLessons" class="grid h-10 w-10 place-items-center rounded-xl border border-red-400/25 text-red-200 hover:bg-red-500/10" type="button" aria-label="Удалить урок" @click="deleteLesson(selectedLesson)"><span class="material-symbols-outlined">delete</span></button><button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" aria-label="Закрыть урок" @click="selectedLesson = null"><span class="material-symbols-outlined">close</span></button></div></div>
          <p class="mb-6 text-base font-semibold leading-7 text-primary/90">{{ selectedLesson.excerpt }}</p>
          <div v-if="selectedLesson.blocks?.length" class="grid gap-6"><template v-for="block in selectedLesson.blocks" :key="block.id"><div v-if="block.type === 'text'" class="whitespace-pre-wrap text-[15px] leading-7 text-on-surface">{{ block.content }}</div><img v-else-if="block.type === 'image'" class="w-full rounded-2xl object-cover" :src="block.image_path" alt="Иллюстрация к уроку" /><div v-else class="relative mx-auto w-full max-w-4xl overflow-hidden rounded-2xl bg-black"><video class="block h-auto w-full object-contain" :src="block.video_path" controls playsinline controlsList="nodownload noplaybackrate" disablePictureInPicture /><VideoWatermark /></div></template></div>
          <template v-else><img v-if="selectedLesson.image_path" class="mb-6 aspect-video w-full rounded-2xl object-cover" :src="selectedLesson.image_path" :alt="selectedLesson.title" /><div class="whitespace-pre-wrap text-[15px] leading-7 text-on-surface">{{ selectedLesson.body }}</div></template>
        </article>
      </div>

      <div v-if="createModalOpen" class="app-modal-backdrop z-[120] bg-black/70 backdrop-blur-sm" @mousedown.self="closeCreateModal">
        <form class="app-modal-panel glass-panel rounded-[28px] p-5 sm:max-w-3xl sm:p-7" role="dialog" aria-modal="true" aria-labelledby="lesson-create-title" @submit.prevent="createLesson">
          <div class="mb-6 flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">{{ editingLesson ? 'Редактирование' : 'Новый материал' }}</p><h3 id="lesson-create-title" class="mt-1 text-2xl font-extrabold">{{ editingLesson ? 'Редактировать урок' : 'Создать урок' }}</h3></div><button class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-on-muted" type="button" :disabled="saving" aria-label="Закрыть" @click="closeCreateModal"><span class="material-symbols-outlined">close</span></button></div>
          <div class="grid gap-4"><label class="grid gap-2 text-sm font-bold text-on-muted">Название<input v-model.trim="form.title" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" required maxlength="255" /></label><label class="grid gap-2 text-sm font-bold text-on-muted">Описание <span class="font-normal text-on-muted/70">необязательно</span><textarea v-model.trim="form.excerpt" class="min-h-24 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" maxlength="500" /></label><fieldset class="grid gap-2"><legend class="text-sm font-bold text-on-muted">Доступ к уроку</legend><div class="grid grid-cols-2 rounded-2xl border border-white/10 bg-surface-low p-1"><button v-for="option in [{ value: 'free', label: 'Бесплатный' }, { value: 'paid', label: 'Платный' }]" :key="option.value" class="rounded-xl px-4 py-3 text-sm font-extrabold transition" :class="form.access_level === option.value ? 'bg-primary text-[#470382]' : 'text-on-muted hover:bg-white/5'" type="button" @click="form.access_level = option.value">{{ option.label }}</button></div></fieldset></div>
          <div class="mt-6 border-t border-white/10 pt-5"><div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h4 class="font-extrabold">Содержание урока</h4><p class="mt-1 text-sm text-on-muted">Добавляйте блоки в нужном порядке: текст, фото и видео.</p></div><div class="flex flex-wrap gap-2"><button class="inline-flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm font-bold text-on-muted" type="button" @click="addTextBlock"><span class="material-symbols-outlined text-[18px]">text_fields</span>Текст</button><button class="inline-flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm font-bold text-on-muted" type="button" @click="addImageBlock"><span class="material-symbols-outlined text-[18px]">image</span>Фото</button><button class="inline-flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm font-bold text-on-muted" type="button" @click="addVideoBlock"><span class="material-symbols-outlined text-[18px]">video_file</span>Видео</button></div></div>
            <div class="mt-4 grid gap-4"><article v-for="(block, index) in form.blocks" :key="index" class="rounded-2xl border border-white/10 bg-surface-low p-4"><div class="mb-3 flex items-center justify-between"><span class="text-sm font-extrabold text-primary">{{ block.type === 'text' ? 'Текстовый блок' : block.type === 'image' ? 'Блок с фото' : 'Видеоблок' }}</span><button v-if="form.blocks.length > 1" class="grid h-8 w-8 place-items-center rounded-lg text-on-muted hover:bg-red-500/15 hover:text-red-200" type="button" aria-label="Удалить блок" @click="removeBlock(index)"><span class="material-symbols-outlined text-[18px]">delete</span></button></div><textarea v-if="block.type === 'text'" v-model="block.content" class="min-h-36 w-full rounded-xl border border-white/10 bg-surface-container px-4 py-3 text-on-surface outline-none focus:border-primary/50" placeholder="Введите текст этого фрагмента урока" required /><label v-else-if="block.type === 'image'" class="grid cursor-pointer gap-3"><img v-if="block.preview" class="max-h-80 w-full rounded-xl object-cover" :src="block.preview" alt="Предпросмотр фото" /><span v-else class="grid aspect-video place-items-center rounded-xl border border-dashed border-white/15 bg-surface-container text-on-muted"><span class="material-symbols-outlined text-4xl">add_photo_alternate</span></span><span class="truncate text-sm font-semibold text-primary">{{ block.file?.name ?? 'Выбрать фото с устройства' }}</span><input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectBlockImage($event, block)" /></label><label v-else class="grid cursor-pointer gap-3"><video v-if="block.preview" class="max-h-80 w-full rounded-xl bg-black" :src="block.preview" controls playsinline /><span v-else class="grid aspect-video place-items-center rounded-xl border border-dashed border-white/15 bg-surface-container text-on-muted"><span class="material-symbols-outlined text-4xl">video_file</span></span><span class="truncate text-sm font-semibold text-primary">{{ block.file?.name ?? 'Выбрать видео с устройства (до 2 ГБ)' }}</span><input class="sr-only" type="file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" @change="selectBlockVideo($event, block)" /></label></article></div>
          </div>
          <p v-if="errorMessage" class="mt-5 rounded-2xl border border-red-400/25 bg-red-500/10 p-3 text-sm font-semibold text-red-200">{{ errorMessage }}</p><p v-else-if="saving" class="mt-5 text-sm font-semibold text-on-muted">{{ uploadProgress > 0 && uploadProgress < 100 ? `Загружаем видео: ${uploadProgress}%. Не закрывайте страницу.` : 'Сохраняем урок в хранилище…' }}</p><div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button class="rounded-2xl border border-white/10 px-5 py-3 text-sm font-bold text-on-muted" type="button" :disabled="saving" @click="closeCreateModal">Отмена</button><button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-3 text-sm font-extrabold text-white disabled:opacity-60" type="submit" :disabled="saving">{{ saving ? (uploadProgress > 0 && uploadProgress < 100 ? `Загрузка ${uploadProgress}%` : 'Сохраняем...') : editingLesson ? 'Сохранить изменения' : 'Опубликовать урок' }}</button></div>
        </form>
      </div>
    </Teleport>
  </section>
</template>
