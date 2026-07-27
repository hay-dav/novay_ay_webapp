<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/services/api';
const route = useRoute();
const course = ref(null);
const lessons = ref([]);
const activeLessonId = ref(null);
const question = ref('');
const sentQuestion = ref(false);
const activeLesson = computed(() => lessons.value.find((lesson) => lesson.id === activeLessonId.value) ?? lessons.value[0]);
onMounted(async () => {
    const { data } = await api.get(`/courses/${route.params.slug}`);
    course.value = data.course;
    lessons.value = data.lessons;
    activeLessonId.value = lessons.value[0]?.id ?? null;
});
async function markLesson(lesson) {
    await api.patch(`/lessons/${lesson.id}/progress`, { progress_percent: 100, status: 'completed' });
    lesson.progress_percent = 100;
}
async function askQuestion() {
    if (!activeLesson.value || !question.value.trim())
        return;
    await api.post(`/lessons/${activeLesson.value.id}/questions`, { question: question.value.trim() });
    question.value = '';
    sentQuestion.value = true;
}
</script>

<template>
  <section v-if="course" class="grid gap-6">
    <div class="relative overflow-hidden rounded-[28px] border border-white/10 bg-surface-container">
      <img
        class="h-[280px] w-full object-cover opacity-70"
        alt="Курс"
        src="https://images.unsplash.com/photo-1549576490-b0b4831ef60a?auto=format&fit=crop&w=1400&q=80"
      />
      <div class="absolute inset-0 bg-gradient-to-t from-surface-lowest via-surface-lowest/40 to-transparent" />
      <div class="absolute bottom-0 left-0 right-0 p-6 lg:p-8">
        <span class="rounded-full bg-primary-container/80 px-3 py-1 text-xs font-bold text-white">
          {{ course.access_level === 'free' ? 'Бесплатный модуль' : 'Платный курс' }}
        </span>
        <h2 class="mt-4 max-w-3xl text-[34px] font-extrabold leading-10 text-on-surface">{{ course.title }}</h2>
        <p class="mt-3 max-w-2xl text-on-muted">{{ course.description }}</p>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_360px]">
      <article class="glass-panel overflow-hidden rounded-[28px]">
        <video
          v-if="activeLesson?.video_path"
          class="h-[360px] w-full bg-surface-low object-cover"
          :src="activeLesson.video_path"
          controls
          controlsList="nodownload noplaybackrate"
          disablePictureInPicture
          oncontextmenu="return false"
        />
        <div v-else class="grid h-[360px] place-items-center bg-surface-container text-center">
          <div>
            <span class="material-symbols-outlined text-[56px] text-primary">article</span>
            <p class="mt-3 font-bold">{{ activeLesson?.type === 'text' ? 'Текстовый урок' : 'Материал урока' }}</p>
          </div>
        </div>
        <div class="p-5">
          <h3 class="text-2xl font-extrabold">{{ activeLesson?.title }}</h3>
          <p class="mt-2 text-sm leading-6 text-on-muted">{{ activeLesson?.description }}</p>
          <div class="mt-5 flex flex-wrap gap-3">
            <button v-if="activeLesson" class="rounded-xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]" type="button" @click="markLesson(activeLesson)">
              {{ activeLesson.progress_percent === 100 ? 'Урок завершен' : 'Отметить урок' }}
            </button>
            <span class="rounded-xl border border-white/10 px-4 py-2 text-sm font-bold text-on-muted">
              {{ Math.ceil((activeLesson?.duration_seconds ?? 0) / 60) }} мин
            </span>
          </div>

          <form class="mt-6 grid gap-3" @submit.prevent="askQuestion">
            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Вопрос по уроку
              <textarea v-model="question" class="min-h-28 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" />
            </label>
            <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white" type="submit">
              Отправить куратору
            </button>
            <p v-if="sentQuestion" class="text-sm font-bold text-primary">Вопрос отправлен.</p>
          </form>
        </div>
      </article>

      <aside class="grid content-start gap-3">
        <article
          v-for="lesson in lessons"
          :key="lesson.id"
          class="cursor-pointer rounded-[24px] border p-4 transition"
          :class="lesson.id === activeLesson?.id ? 'border-primary/40 bg-primary/10' : 'border-white/10 bg-surface-container'"
          @click="activeLessonId = lesson.id"
        >
          <div class="flex items-center gap-4">
            <div class="grid h-12 w-12 place-items-center rounded-2xl bg-primary/15 text-primary">
              <span class="material-symbols-outlined">{{ lesson.type === 'podcast' ? 'headphones' : lesson.type === 'stream' ? 'live_tv' : lesson.type === 'text' ? 'article' : 'play_arrow' }}</span>
            </div>
            <div>
              <strong class="block">{{ lesson.title }}</strong>
              <span class="text-sm text-on-muted">{{ lesson.type }} · {{ Math.ceil(lesson.duration_seconds / 60) }} мин</span>
            </div>
          </div>
        </article>
      </aside>
    </div>
  </section>
</template>
