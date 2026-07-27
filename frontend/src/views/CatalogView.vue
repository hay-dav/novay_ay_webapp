<script setup>
import { onMounted, ref } from 'vue';
import { useCatalogStore } from '@/stores/catalog';
import { useAuthStore } from '@/stores/auth';
import CourseCard from '@/components/CourseCard.vue';
const catalog = useCatalogStore();
const auth = useAuthStore();
const filters = ['Все', 'Тренировки', 'Питание', 'Подкасты', 'Эфиры'];
const showMaterialModal = ref(false);
const material = ref({
    title: '',
    description: '',
    file: null,
});
const saving = ref(false);
const saved = ref(false);
onMounted(() => catalog.fetchCourses());
function selectFile(event) {
    const input = event.target;
    material.value.file = input.files?.[0] ?? null;
}
async function createMaterial() {
    saving.value = true;
    saved.value = false;
    try {
        await catalog.addMaterial(material.value);
        material.value = { title: '', description: '', file: null };
        saved.value = true;
        showMaterialModal.value = false;
    }
    finally {
        saving.value = false;
    }
}
</script>

<template>
  <section>
    <div class="mb-7 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">каталог</p>
        <h2 class="mt-2 text-[32px] font-extrabold leading-10">Видеоуроки и курсы</h2>
        <p class="mt-2 max-w-2xl text-on-muted">Тренировки, подкасты, записи эфиров и материалы программы в одном месте.</p>
      </div>

      <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
        <button
          v-if="auth.isTrainer"
          class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-4 text-sm font-extrabold text-white"
          type="button"
          @click="showMaterialModal = true"
        >
          Добавить материал
        </button>
        <label class="relative block w-full lg:w-[360px]">
          <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">search</span>
          <input
            class="h-13 w-full rounded-2xl border border-white/10 bg-surface-container py-4 pl-12 pr-4 text-sm text-on-surface outline-none focus:border-primary/50"
            placeholder="Найти урок"
            type="search"
          />
        </label>
      </div>
    </div>

    <div v-if="saved" class="mb-5 rounded-2xl border border-primary/20 bg-primary/10 p-4 text-sm font-bold text-primary">
      Материал добавлен и появится в личных кабинетах участниц с доступом.
    </div>

    <div class="mb-6 flex gap-2 overflow-x-auto pb-2">
      <button
        v-for="(filter, index) in filters"
        :key="filter"
        class="shrink-0 rounded-full border px-4 py-2 text-sm font-bold"
        :class="index === 0 ? 'border-primary bg-primary text-[#470382]' : 'border-white/10 bg-surface-container text-on-muted'"
      >
        {{ filter }}
      </button>
    </div>

    <div v-if="catalog.materials.length" class="mb-8">
      <div class="mb-4 flex items-center justify-between">
        <h3 class="text-2xl font-extrabold">Новые уроки и материалы</h3>
        <span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">{{ catalog.materials.length }}</span>
      </div>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <RouterLink
          v-for="material in catalog.materials"
          :key="material.id"
          :to="`/courses/${material.course_slug}`"
          class="rounded-[24px] border border-white/10 bg-surface-container p-5 transition hover:-translate-y-1 hover:border-primary/30"
        >
          <div class="mb-4 flex items-center justify-between gap-3">
            <span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">{{ material.type === 'video' ? 'Видео' : 'Материал' }}</span>
            <span class="material-symbols-outlined text-primary">play_circle</span>
          </div>
          <h4 class="text-lg font-extrabold leading-6">{{ material.title }}</h4>
          <p class="mt-2 line-clamp-2 text-sm leading-6 text-on-muted">{{ material.description }}</p>
          <p class="mt-4 text-xs font-bold uppercase tracking-[0.14em] text-on-muted">{{ material.course_title }}</p>
        </RouterLink>
      </div>
    </div>

    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <CourseCard v-for="(course, index) in catalog.courses" :key="course.id" :course="course" :index="index" />
    </div>

    <Teleport to="body">
      <div v-if="showMaterialModal" class="fixed inset-0 z-[100] grid place-items-center bg-black/60 p-5 backdrop-blur-sm">
        <form class="glass-panel w-full max-w-xl rounded-[28px] p-5" @submit.prevent="createMaterial">
          <div class="mb-5 flex items-center justify-between">
            <h3 class="text-2xl font-extrabold">Добавить материал</h3>
            <button class="grid h-10 w-10 place-items-center rounded-2xl border border-white/10 text-on-muted" type="button" @click="showMaterialModal = false">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <div class="grid gap-4">
            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Название
              <input v-model="material.title" required class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" />
            </label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Описание
              <textarea v-model="material.description" required class="min-h-32 rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" />
            </label>
            <label class="grid gap-2 text-sm font-bold text-on-muted">
              Файл с устройства
              <input class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none file:mr-4 file:rounded-xl file:border-0 file:bg-primary file:px-4 file:py-2 file:font-bold file:text-[#470382]" type="file" @change="selectFile" />
            </label>
          </div>

          <button class="mt-5 w-full rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white" type="submit" :disabled="saving">
            {{ saving ? 'Создаю...' : 'Создать урок' }}
          </button>
        </form>
      </div>
    </Teleport>
  </section>
</template>
