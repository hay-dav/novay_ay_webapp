<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
const auth = useAuthStore();
const date = ref(new Date().toISOString().slice(0, 10));
const entries = ref([]);
const recipes = ref([]);
const comments = ref([]);
const loading = ref(false);
const errorMessage = ref('');
const summary = ref({
    calories: 0,
    protein_g: 0,
    fat_g: 0,
    carbs_g: 0,
    goals: { calories: 0, protein_g: 0, fat_g: 0, carbs_g: 0 },
});
const form = ref({
    meal_type: 'Завтрак',
    title: '',
    calories: null,
    protein_g: null,
    fat_g: null,
    carbs_g: null,
});
const goalForm = ref({ calorie_goal: null });
const editingGoal = ref(false);
const savingGoal = ref(false);
const goalError = ref('');
const caloriePercent = computed(() => Math.min(100, Math.round((summary.value.calories / summary.value.goals.calories) * 100)));
async function loadDiary() {
    const { data } = await api.get('/food-diary', { params: { date: date.value } });
    entries.value = data.data;
    summary.value = data.summary;
    if (!editingGoal.value)
        goalForm.value.calorie_goal = data.summary.goals.calories;
}
async function loadRecipes() {
    const { data } = await api.get('/recipes');
    recipes.value = data.data;
}
async function loadComments() {
    if (auth.user?.role !== 'client') {
        comments.value = [];
        return;
    }
    const { data } = await api.get('/food-diary/comments');
    comments.value = data.data;
}
async function loadPage() {
    loading.value = true;
    errorMessage.value = '';
    try {
        await Promise.all([loadDiary(), loadRecipes(), loadComments()]);
    }
    catch (error) {
        if (error.response?.status === 401) {
            errorMessage.value = 'Сессия истекла. Войдите снова, чтобы открыть дневник питания.';
            return;
        }
        errorMessage.value = 'Не удалось загрузить дневник питания. Попробуйте обновить страницу.';
    }
    finally {
        loading.value = false;
    }
}
async function addEntry() {
    const { data } = await api.post('/food-diary', { ...form.value, eaten_on: date.value });
    entries.value = [data.data, ...entries.value];
    await loadDiary();
    form.value = {
        meal_type: form.value.meal_type,
        title: '',
        calories: null,
        protein_g: null,
        fat_g: null,
        carbs_g: null,
    };
}
function openGoalEditor() {
    goalError.value = '';
    goalForm.value.calorie_goal = summary.value.goals.calories;
    editingGoal.value = true;
}
async function saveGoal() {
    savingGoal.value = true;
    goalError.value = '';
    try {
        const { data } = await api.patch('/food-diary/goals', goalForm.value);
        summary.value.goals.calories = data.data.calorie_goal;
        editingGoal.value = false;
    }
    catch (error) {
        goalError.value = error.response?.data?.errors?.calorie_goal?.[0] ?? 'Не удалось сохранить цель. Попробуйте ещё раз.';
    }
    finally {
        savingGoal.value = false;
    }
}
async function addRecipe(recipe) {
    await api.post('/food-diary', {
        recipe_id: recipe.id,
        meal_type: recipe.category,
        eaten_on: date.value,
        is_favorite: true,
    });
    await loadDiary();
}
function formatCommentDate(value) {
    return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(value));
}
onMounted(loadPage);
</script>

<template>
  <section class="grid gap-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">питание</p>
        <h2 class="mt-2 text-[32px] font-extrabold leading-10">Дневник и рецепты</h2>
      </div>
      <input
        v-model="date"
        class="rounded-2xl border border-white/10 bg-surface-container px-4 py-3 text-sm font-bold text-on-surface outline-none focus:border-primary/50"
        type="date"
        @change="loadDiary"
      />
    </div>

    <div v-if="errorMessage" class="rounded-2xl border border-danger/20 bg-danger-container/20 p-4 text-sm font-bold text-danger">
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="glass-panel rounded-[28px] p-5 text-sm text-on-muted">
      Загружаю дневник питания...
    </div>

    <div v-else class="grid gap-5 lg:grid-cols-[360px_1fr]">
      <article class="glass-panel rounded-[28px] p-5">
        <div class="mb-5 flex items-center justify-between">
          <div>
            <p class="text-sm font-semibold text-primary">Цель на день</p>
            <h3 class="text-2xl font-extrabold">{{ summary.calories }} / {{ summary.goals.calories }} ккал</h3>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="auth.user?.role === 'client'"
              class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-primary transition hover:bg-primary/10"
              type="button"
              title="Изменить цель по калориям"
              aria-label="Изменить цель по калориям"
              @click="openGoalEditor"
            >
              <span class="material-symbols-outlined text-[20px]">edit</span>
            </button>
            <div class="grid h-14 w-14 place-items-center rounded-2xl bg-primary/15 text-primary">
              <span class="material-symbols-outlined">local_fire_department</span>
            </div>
          </div>
        </div>
        <form v-if="editingGoal" class="mb-5 grid gap-3 rounded-2xl border border-primary/25 bg-primary/5 p-4" @submit.prevent="saveGoal">
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Цель по калориям, ккал
            <input v-model.number="goalForm.calorie_goal" required min="1" max="10000" class="rounded-xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" type="number" />
          </label>
          <p v-if="goalError" class="text-xs font-semibold text-danger">{{ goalError }}</p>
          <div class="flex gap-2">
            <button class="rounded-xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382] disabled:opacity-60" type="submit" :disabled="savingGoal">{{ savingGoal ? 'Сохраняем...' : 'Сохранить цель' }}</button>
            <button class="rounded-xl border border-white/10 px-4 py-2 text-sm font-bold text-on-muted" type="button" :disabled="savingGoal" @click="editingGoal = false">Отмена</button>
          </div>
        </form>
        <div class="h-3 overflow-hidden rounded-full bg-surface-high">
          <div class="h-full rounded-full bg-primary" :style="{ width: `${caloriePercent}%` }" />
        </div>
        <div class="mt-5 grid grid-cols-3 gap-3 text-sm">
          <div class="rounded-2xl bg-surface-container p-3">
            <span class="block text-on-muted">Белки</span>
            <b>{{ summary.protein_g }} / {{ summary.goals.protein_g }} г</b>
          </div>
          <div class="rounded-2xl bg-surface-container p-3">
            <span class="block text-on-muted">Жиры</span>
            <b>{{ summary.fat_g }} / {{ summary.goals.fat_g }} г</b>
          </div>
          <div class="rounded-2xl bg-surface-container p-3">
            <span class="block text-on-muted">Углеводы</span>
            <b>{{ summary.carbs_g }} / {{ summary.goals.carbs_g }} г</b>
          </div>
        </div>

        <form class="mt-6 grid gap-3" @submit.prevent="addEntry">
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Прием пищи
            <select v-model="form.meal_type" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50">
              <option>Завтрак</option>
              <option>Обед</option>
              <option>Перекус</option>
              <option>Ужин</option>
            </select>
          </label>
          <label class="grid gap-2 text-sm font-bold text-on-muted">
            Блюдо или продукт
            <input v-model="form.title" required class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none focus:border-primary/50" />
          </label>
          <div class="grid grid-cols-2 gap-3">
            <input v-model.number="form.calories" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" type="number" placeholder="ккал" />
            <input v-model.number="form.protein_g" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" type="number" placeholder="белки" />
            <input v-model.number="form.fat_g" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" type="number" placeholder="жиры" />
            <input v-model.number="form.carbs_g" class="rounded-2xl border border-white/10 bg-surface-low px-4 py-3 text-on-surface outline-none" type="number" placeholder="углеводы" />
          </div>
          <button class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-4 font-extrabold text-white" type="submit">
            Добавить в дневник
          </button>
        </form>
      </article>

      <div class="grid gap-5">
        <article class="glass-panel rounded-[28px] p-5">
          <div class="mb-5 flex items-center justify-between">
            <h3 class="text-xl font-extrabold">Рацион дня</h3>
            <span class="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary">{{ entries.length }} записей</span>
          </div>
          <div class="grid gap-3">
            <div v-for="entry in entries" :key="entry.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
              <span class="text-xs font-bold uppercase tracking-[0.14em] text-primary">{{ entry.meal_type }}</span>
              <h4 class="mt-2 font-extrabold">{{ entry.title }}</h4>
              <p class="mt-2 text-sm text-on-muted">{{ entry.calories }} ккал · Б {{ entry.protein_g }} · Ж {{ entry.fat_g }} · У {{ entry.carbs_g }}</p>
            </div>
            <div v-if="!entries.length" class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">
              На выбранную дату пока нет записей.
            </div>
          </div>
        </article>

        <article v-if="auth.user?.role === 'client'" class="glass-panel rounded-[28px] p-5">
          <div class="mb-5 flex items-center justify-between">
            <h3 class="text-xl font-extrabold">Комментарии команды</h3>
            <span class="material-symbols-outlined text-primary">chat</span>
          </div>
          <div v-if="comments.length" class="grid gap-3">
            <article v-for="comment in comments" :key="comment.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <strong>{{ comment.author?.name ?? 'Команда сопровождения' }}</strong>
                <span class="text-xs font-semibold text-on-muted">{{ formatCommentDate(comment.created_at) }}</span>
              </div>
              <p class="mt-3 whitespace-pre-line text-sm leading-6 text-on-muted">{{ comment.body }}</p>
            </article>
          </div>
          <p v-else class="rounded-2xl border border-white/10 bg-surface-container p-4 text-sm text-on-muted">Комментариев от команды пока нет.</p>
        </article>

        <article class="glass-panel rounded-[28px] p-5">
          <div class="mb-5 flex items-center justify-between">
            <h3 class="text-xl font-extrabold">Шаблоны рецептов</h3>
            <span class="material-symbols-outlined text-primary">restaurant_menu</span>
          </div>
          <div class="grid gap-3 lg:grid-cols-3">
            <article v-for="recipe in recipes" :key="recipe.id" class="rounded-2xl border border-white/10 bg-surface-container p-4">
              <span class="text-xs font-bold uppercase tracking-[0.14em] text-primary">{{ recipe.category }}</span>
              <h4 class="mt-2 font-extrabold">{{ recipe.title }}</h4>
              <p class="mt-2 line-clamp-2 text-sm text-on-muted">{{ recipe.ingredients }}</p>
              <button class="mt-4 rounded-xl bg-primary px-4 py-2 text-sm font-extrabold text-[#470382]" @click="addRecipe(recipe)">
                В дневник
              </button>
            </article>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>
