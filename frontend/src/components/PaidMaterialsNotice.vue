<template>
  <article class="paid-materials-notice glass-panel grid min-h-64 place-items-center rounded-[28px] p-6 text-center sm:p-8">
    <div class="grid justify-items-center gap-5">
      <span class="material-symbols-outlined text-[48px] text-primary" aria-hidden="true">lock</span>
      <h3 class="max-w-lg text-xl font-extrabold leading-7">Материалы доступны на платном тарифе</h3>
      <RouterLink to="/tariffs" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-primary px-6 py-3 text-sm font-extrabold text-[#470382] transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">Выбрать тариф</RouterLink>
    </div>
  </article>
</template>
