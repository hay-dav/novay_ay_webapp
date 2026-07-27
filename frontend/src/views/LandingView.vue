<script setup>
import { ref } from 'vue';

const heroImage = '/public-image/HeroFoto.jpeg';
const resultImages = [
    '/public-image/c8f78c21-34a7-445b-afda-0fa7f2abffb0-10365090.jpeg',
    '/public-image/b0867aa5-b051-4182-80d9-7d668eb13ad5-10368769.jpeg',
    '/public-image/a059be4d-2f00-4d98-9d2f-7534977f1332-10368785.jpeg',
    '/public-image/99183f10-290c-4d6c-995a-d2e62666b5d9-10368775.jpeg',
    '/public-image/d2deb7e7-fa52-4930-b5cd-b9ce0d0a5043-10368788.jpeg',
    '/public-image/8e5553b1-dfa1-4c3c-96ba-0382c0c3a4c2-10368834.jpeg',
    '/public-image/d52511b4-e54e-43a3-9cc1-c5ff9d867a9c-10368822.jpeg',
    '/public-image/f8c61f84-d284-4e92-981d-147b4388c4c5-10368821.jpeg',
    '/public-image/0da04d54-c04a-4b9a-8723-416c66f3ec21-10368824.jpeg',
];
const courseItems = [
    { number: '01', title: 'Личное сопровождение', text: 'Мы всегда с вами на связи лично. За ручку приведем к результату.' },
    { number: '02', title: 'Видеоуроки', text: 'Подробные памятки, примеры блюд, рецепты и рекомендации продуктов.' },
    { number: '03', title: 'Тренировки онлайн', text: 'Тренировки по желанию, и без тренировок будет отличный результат.' },
    { number: '04', title: 'Бонусы', text: 'Прямые эфиры с приглашенными экспертами: психологом, косметологом, визажистом и другими.' },
    { number: '05', title: 'Чат единомышленников', text: 'Общение в дружном и поддерживающем чате единомышленников.' },
    { number: '06', title: 'Подкасты о важном', text: 'На курсе уделяем время не только питанию и спорту, но и работе над собой.' },
];
const tariffs = [
    {
        title: 'Личное сопровождение с куратором',
        period: '1 месяц',
        price: '6 990 ₽',
        oldPrice: '8 555 ₽',
        href: 'https://lazareva-secret.tb.ru/555/oplata',
        image: '/public-image/2f54616f-69d4-427c-956f-1641a54adfcb-10359097.jpeg',
    },
    {
        title: 'Личное сопровождение с куратором',
        period: '3 месяца',
        price: '12 990 ₽',
        oldPrice: '16 990 ₽',
        href: 'https://lazareva-secret.tb.ru/555/page2',
        image: '/public-image/771ef69d-9c75-4e2e-9386-2a04eae07667-10365209.jpeg',
    },
    {
        title: 'VIP сопровождение с Анастасией',
        period: '1 месяц',
        price: '13 555 ₽',
        oldPrice: '16 555 ₽',
        href: 'https://lazareva-secret.tb.ru/555/page3',
        image: '/public-image/89a9161d-54a6-4120-ba4e-de4ada261f91-10365407.jpeg',
    },
    {
        title: 'VIP сопровождение с Анастасией',
        period: '3 месяца',
        price: '29 555 ₽',
        oldPrice: '32 555 ₽',
        href: 'https://lazareva-secret.tb.ru/555/page4',
        image: '/public-image/6a0a0768-095a-4b34-99f4-7ca46a0babb3-10365406.jpeg',
    },
];
const advantages = [
    {
        title: 'Без запретов в питании',
        text: 'На курсе нет запретов в питании и готового меню. Продукты выбираете на свой вкус. Первые результаты видны уже через 7-10 дней.',
        image: '/public-image/68e9984c-c0fa-4aca-a358-01dee9ad00b8-10339613.png',
    },
    {
        title: 'Гарантия результата',
        text: 'При соблюдении всех рекомендаций результат гарантирован. Если результата не будет, деньги за курс возвращаются.',
        image: '/public-image/74312d23-03c8-4bef-9125-5f3656365399-10339612.png',
    },
    {
        title: 'Знания, а не готовое меню',
        text: 'Через месяц вы поймете основные принципы питания и сможете двигаться к цели самостоятельно или продлевать курс в сообществе.',
        image: '/public-image/70e70fd0-d57e-4e92-b382-15b8d623520a-10339614.png',
    },
    {
        title: 'Личное сопровождение и поддержка',
        text: 'Мы поможем максимально легко и эффективно пройти курс. За ручку приведем к результату.',
        image: '/public-image/70e70fd0-d57e-4e92-b382-15b8d623520a-10339614.png',
    },
];
const reviewImages = [
    '/public-image/cee4e6ca-0c81-4e1d-b2bb-b2e4f8bb0629-10359367.jpeg',
    '/public-image/1d2c0521-fa29-49f5-a92b-151410aab971-10359368.jpeg',
    '/public-image/6ecb1d55-4f9d-433c-8668-195b1d89361c-10359369.jpeg',
];
const resultTrack = ref(null);
const activeResult = ref(0);
const resultSlideStep = 335;

function goToResult(index) {
    const nextIndex = Math.min(Math.max(index, 0), resultImages.length - 1);
    activeResult.value = nextIndex;
    resultTrack.value?.scrollTo({ left: nextIndex * resultSlideStep, behavior: 'smooth' });
}
function previousResult() {
    goToResult(activeResult.value === 0 ? resultImages.length - 1 : activeResult.value - 1);
}
function nextResult() {
    goToResult(activeResult.value === resultImages.length - 1 ? 0 : activeResult.value + 1);
}
function updateActiveResult() {
    if (!resultTrack.value)
        return;
    activeResult.value = Math.min(
        resultImages.length - 1,
        Math.max(0, Math.round(resultTrack.value.scrollLeft / resultSlideStep)),
    );
}
</script>

<template>
  <main class="app-gradient min-h-screen overflow-x-hidden text-on-surface">
    <header class="fixed inset-x-0 top-0 z-50 border-b border-white/10 bg-surface/75 backdrop-blur-xl">
      <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-10">
        <a class="flex h-12 items-center" href="#hero" aria-label="Новая Я">
          <img class="h-full w-[154px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
        </a>
        <nav class="hidden items-center gap-6 text-sm font-semibold text-on-muted md:flex">
          <a href="#about" class="transition hover:text-primary">Обо мне</a>
          <a href="#results" class="transition hover:text-primary">Результаты</a>
          <a href="#program" class="transition hover:text-primary">Программа</a>
          <a href="#tariffs" class="transition hover:text-primary">Тарифы</a>
          
        </nav>

       <RouterLink to="/login"  class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-5 py-2.5 text-sm font-extrabold text-white shadow-[0_12px_32px_rgba(109,56,168,0.32)]">Личный кабинет</RouterLink>
      </div>
    </header>

    <section id="hero" class="relative mx-auto grid min-h-screen max-w-7xl items-center gap-12 px-5 pb-20 pt-32 lg:grid-cols-[1.05fr_0.95fr] lg:px-10">
      <div class="absolute left-1/4 top-1/4 -z-0 h-[460px] w-[460px] rounded-full bg-primary-container/20 blur-[120px]" />
      <div class="relative z-10">
        <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-surface-high px-4 py-2">
          <span class="material-symbols-outlined text-[18px] text-primary" style="font-variation-settings: 'FILL' 1">star</span>
          <span class="text-xs font-semibold uppercase tracking-[0.12em] text-on-muted">Стань лучшей версией Себя</span>
        </div>

        <h1 class="text-glow mt-7 text-[46px] font-extrabold leading-[52px] tracking-tight text-on-surface lg:text-[74px] lg:leading-[82px]">
          Курс <span class="text-primary">«Новая Я»</span>
        </h1>
        <p class="mt-6 max-w-2xl text-[22px] leading-8 text-on-surface lg:text-[30px] lg:leading-10">
          Курс по снижению веса с индивидуальным сопровождением
        </p>
        <p class="mt-6 max-w-xl text-lg leading-8 text-on-muted">
          Более 4000 женщин снизили вес комфортно, без ограничений в питании и научились этот вес поддерживать.
        </p>

        <div class="mt-10 flex flex-col gap-4 sm:flex-row">
          <a
            href="#tariffs"
            class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-8 py-4 text-center font-extrabold text-white shadow-[0_16px_44px_rgba(109,56,168,0.38)] transition hover:scale-[1.02]"
          >
            Выбрать тариф
          </a>
          <a href="#program" class="rounded-2xl border border-white/15 px-8 py-4 text-center font-extrabold text-on-surface transition hover:bg-white/5">
            Что внутри курса
          </a>
        </div>

        <div class="mt-10 grid grid-cols-3 gap-5 border-t border-white/10 pt-8">
          <div>
            <strong class="block text-2xl font-extrabold text-primary">4000+</strong>
            <span class="text-xs font-semibold text-on-muted">участниц</span>
          </div>
          <div>
            <strong class="block text-2xl font-extrabold text-primary">7-10</strong>
            <span class="text-xs font-semibold text-on-muted">дней до первых результатов</span>
          </div>
          <div>
            <strong class="block text-2xl font-extrabold text-primary">0</strong>
            <span class="text-xs font-semibold text-on-muted">запретов в питании</span>
          </div>
        </div>
      </div>

      <div class="relative z-10">
        <div class="glass-panel overflow-hidden rounded-[32px] p-4">
          <img class="h-[560px] w-full rounded-[24px] object-cover" :src="heroImage" alt="Курс Новая Я" />
        </div>
        <div class="glass-panel absolute -bottom-6 left-4 right-4 rounded-[24px] p-5 lg:-left-8 lg:right-auto lg:w-[320px]">
          <p class="text-sm font-bold text-primary">Автор курса</p>
          <h2 class="mt-1 text-xl font-extrabold">Лазарева Анастасия</h2>
          <p class="mt-2 text-sm leading-6 text-on-muted">Дипломированный нутрициолог, профессиональный тренер и основатель авторского курса.</p>
        </div>
      </div>
    </section>

    <section id="about" class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <article class="glass-panel rounded-[32px] p-7 lg:p-10">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">обо мне</p>
        <h2 class="mt-3 text-[34px] font-extrabold leading-10">Лазарева Анастасия</h2>
        <p class="mt-6 max-w-4xl text-lg leading-8 text-on-muted">
          Дипломированный нутрициолог. Член ассоциации нутрициологов и коучей по здоровью. Профессиональный тренер.
          Основатель авторского курса «Новая Я».
        </p>
      </article>
    </section>

    <section id="results" class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <div class="mb-8 flex items-end justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">результаты</p>
          <h2 class="mt-3 text-[34px] font-extrabold leading-10">Результаты участниц</h2>
        </div>
        <span class="hidden text-sm font-bold uppercase tracking-[0.18em] text-on-muted md:block">до / после</span>
      </div>
      <div class="relative">
        <div
          ref="resultTrack"
          class="flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          tabindex="0"
          aria-label="Галерея результатов участниц"
          @scroll.passive="updateActiveResult"
          @keydown.left.prevent="previousResult"
          @keydown.right.prevent="nextResult"
        >
          <img
            v-for="(image, index) in resultImages"
            :key="image"
            class="h-[420px] w-[315px] shrink-0 snap-start rounded-[28px] border border-white/10 object-cover shadow-[0_16px_44px_rgba(0,0,0,0.28)]"
            :class="{ 'ring-2 ring-primary/70 ring-offset-2 ring-offset-surface': activeResult === index }"
            :src="image"
            alt="Результат участницы курса"
          />
        </div>

        <button
          class="absolute left-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-surface/90 text-primary shadow-lg backdrop-blur transition hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary"
          type="button"
          title="Предыдущий результат"
          aria-label="Предыдущий результат"
          @click="previousResult"
        >
          <span class="material-symbols-outlined">arrow_back</span>
        </button>
        <button
          class="absolute right-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-white/15 bg-surface/90 text-primary shadow-lg backdrop-blur transition hover:bg-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-primary"
          type="button"
          title="Следующий результат"
          aria-label="Следующий результат"
          @click="nextResult"
        >
          <span class="material-symbols-outlined">arrow_forward</span>
        </button>
      </div>

      <div class="mt-5 flex items-center justify-center gap-2" aria-label="Пагинация результатов">
        <button
          v-for="(_, index) in resultImages"
          :key="`result-page-${index}`"
          class="h-2.5 rounded-full transition-all focus:outline-none focus:ring-2 focus:ring-primary"
          :class="activeResult === index ? 'w-7 bg-primary' : 'w-2.5 bg-white/25 hover:bg-white/50'"
          type="button"
          :title="`Показать результат ${index + 1}`"
          :aria-label="`Показать результат ${index + 1}`"
          :aria-current="activeResult === index ? 'true' : undefined"
          @click="goToResult(index)"
        />
      </div>
    </section>

    <section id="program" class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <div class="mb-10">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">программа</p>
        <h2 class="mt-3 text-[34px] font-extrabold leading-10">Что вас ждет на курсе</h2>
      </div>
      <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="item in courseItems" :key="item.number" class="glass-panel rounded-[28px] p-6">
          <span class="text-[46px] font-extrabold leading-none text-primary">{{ item.number }}</span>
          <h3 class="mt-8 text-xl font-extrabold uppercase">{{ item.title }}</h3>
          <p class="mt-4 text-base leading-7 text-on-muted">{{ item.text }}</p>
        </article>
      </div>
    </section>

    <section id="tariffs" class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <div class="mb-10">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">стоимость</p>
        <h2 class="mt-3 text-[38px] font-extrabold leading-10">Тарифы</h2>
      </div>
      <div class="grid gap-6 md:grid-cols-2">
        <article v-for="tariff in tariffs" :key="tariff.href" class="group relative min-h-[390px] overflow-hidden rounded-[32px] border border-white/10 bg-surface-container">
          <img class="absolute inset-0 h-full w-full object-cover opacity-55 transition duration-500 group-hover:scale-105" :src="tariff.image" :alt="tariff.title" />
          <div class="absolute inset-0 bg-gradient-to-t from-surface-lowest via-surface-lowest/70 to-surface-lowest/20" />
          <div class="relative flex min-h-[390px] flex-col justify-end p-7">
            <h3 class="max-w-md text-2xl font-extrabold">{{ tariff.title }}</h3>
            <p class="mt-1 text-xl font-bold text-on-muted">{{ tariff.period }}</p>
            <p class="mt-5 text-primary">
              Стоимость <strong class="text-2xl">{{ tariff.price }}</strong>, вместо
              <span class="line-through text-on-muted">{{ tariff.oldPrice }}</span>
            </p>
            <a
              :href="tariff.href"
              target="_blank"
              rel="noreferrer"
              class="mt-6 w-max rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-7 py-3 font-extrabold text-white shadow-[0_12px_32px_rgba(109,56,168,0.32)]"
            >
              Купить со скидкой
            </a>
          </div>
        </article>
      </div>
    </section>

    <section class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <div class="mb-10">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">почему это работает</p>
        <h2 class="mt-3 text-[34px] font-extrabold leading-10">Преимущества курса «Новая Я»</h2>
      </div>
      <div class="grid gap-5 md:grid-cols-2">
        <article v-for="advantage in advantages" :key="advantage.title" class="glass-panel flex gap-5 rounded-[28px] p-6">
          <img class="h-16 w-16 shrink-0 object-contain" :src="advantage.image" :alt="advantage.title" />
          <div>
            <h3 class="text-xl font-extrabold">{{ advantage.title }}</h3>
            <p class="mt-3 leading-7 text-on-muted">{{ advantage.text }}</p>
          </div>
        </article>
      </div>
    </section>

    <section class="mx-auto max-w-7xl px-5 py-20 lg:px-10">
      <div class="mb-8">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">социальное доказательство</p>
        <h2 class="mt-3 text-[34px] font-extrabold leading-10">Отзывы</h2>
      </div>
      <div class="grid gap-5 md:grid-cols-3">
        <img
          v-for="image in reviewImages"
          :key="image"
          class="rounded-[28px] border border-white/10 object-cover shadow-[0_16px_44px_rgba(0,0,0,0.28)]"
          :src="image"
          alt="Отзыв участницы"
        />
      </div>
    </section>

    <section class="sticky bottom-0 z-40 border-t border-white/10 bg-surface/90 px-5 py-4 backdrop-blur-xl lg:px-10">
      <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-lg font-semibold">Курс можно приобрести в рассрочку</p>
        <a
          href="https://kkbxz.tb.ru/page4"
          target="_blank"
          rel="noreferrer"
          class="rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-6 py-3 text-center font-extrabold text-white"
        >
          Узнать подробнее
        </a>
      </div>
    </section>

    <footer class="border-t border-white/10 px-5 py-8 lg:px-10">
      <div class="mx-auto flex max-w-7xl flex-col gap-3 text-sm text-on-muted sm:flex-row sm:items-center sm:justify-between">
        <p>© {{ new Date().getFullYear() }} «Новая Я»</p>
        <RouterLink to="/privacy-policy" class="w-max font-semibold text-primary underline underline-offset-4 transition hover:text-white">
          Политика конфиденциальности
        </RouterLink>
      </div>
    </footer>
  </main>
</template>
