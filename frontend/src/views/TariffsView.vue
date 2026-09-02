<script setup>
import { tariffs } from '@/data/tariffs';
</script>

<template>
  <section class="mx-auto max-w-7xl px-5 py-2 sm:py-5 lg:px-10">
    <div class="mb-8 sm:mb-10">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">стоимость</p>
      <h1 class="mt-3 text-[34px] font-extrabold leading-10 sm:text-[38px]">Тарифы</h1>
      <p class="mt-3 max-w-2xl text-sm leading-6 text-on-muted sm:text-base">Выберите формат сопровождения, который подходит вам сейчас.</p>
    </div>

    <div class="grid gap-5 md:grid-cols-2 md:gap-6">
      <article v-for="tariff in tariffs" :key="tariff.href" class="group relative min-h-[390px] overflow-hidden rounded-[28px] border border-white/10 bg-surface-container sm:rounded-[32px]">
        <img class="absolute inset-0 h-full w-full object-cover opacity-55 transition duration-500 group-hover:scale-105" :src="tariff.image" :alt="tariff.title" />
        <div class="absolute inset-0 bg-gradient-to-t from-surface-lowest via-surface-lowest/70 to-surface-lowest/20" />
        <div class="relative flex min-h-[390px] flex-col justify-end p-6 sm:p-7">
          <h2 class="max-w-md text-2xl font-extrabold">{{ tariff.title }}</h2>
          <p class="mt-1 text-xl font-bold text-on-muted">{{ tariff.period }}</p>
          <p class="mt-5 text-primary">
            Стоимость <strong class="text-2xl">{{ tariff.price }}</strong>, вместо
            <span class="line-through text-on-muted">{{ tariff.oldPrice }}</span>
          </p>
          <a :href="tariff.href" target="_blank" rel="noreferrer" class="mt-6 inline-flex min-h-12 w-max items-center rounded-2xl bg-gradient-to-br from-primary-container to-primary-strong px-7 py-3 font-extrabold text-white shadow-[0_12px_32px_rgba(109,56,168,0.32)] transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-container">
            Купить со скидкой
          </a>
        </div>
      </article>
    </div>
  </section>
</template>
