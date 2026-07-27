<script setup>
const operatorEmail = 'lazareva.secret@yandex.ru';
</script>

<template>
  <main class="app-gradient min-h-screen px-5 py-8 text-on-surface lg:px-10">
    <header class="mx-auto flex max-w-4xl items-center justify-between gap-4">
      <RouterLink to="/" class="flex h-12 items-center" aria-label="На главную «Новая Я»">
        <img class="h-full w-[164px] object-contain object-left [filter:brightness(0)_invert(1)_drop-shadow(0_0_8px_rgba(255,255,255,0.45))]" src="/public-image/novaya-ya-logo-header.png" alt="Новая Я, Курс Лазаревой" />
      </RouterLink>
      <RouterLink to="/login" class="rounded-2xl border border-white/10 px-5 py-2.5 text-sm font-extrabold text-on-surface transition hover:border-primary/60 hover:text-primary">
        Войти
      </RouterLink>
    </header>

    <article class="glass-panel mx-auto my-10 max-w-4xl rounded-[28px] p-6 sm:p-10">
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary/80">документы</p>
      <h1 class="mt-3 text-3xl font-extrabold leading-tight sm:text-4xl">Политика конфиденциальности</h1>
      <p class="mt-3 text-sm text-on-muted">Редакция от 26 июля 2026 г.</p>

      <div class="mt-10 grid gap-8 leading-7 text-on-muted">
        <section>
          <h2 class="text-xl font-extrabold text-on-surface">1. Общие положения</h2>
          <p class="mt-3">Настоящая Политика определяет порядок обработки и защиты персональных данных пользователей веб-приложения «Новая Я». Оператор персональных данных — ИП Лазарева А.В.</p>
          <p class="mt-3">По вопросам обработки данных и для отзыва согласия можно обратиться по адресу <a :href="`mailto:${operatorEmail}`" class="font-semibold text-primary underline underline-offset-4">{{ operatorEmail }}</a>.</p>
        </section>

        <section>
          <h2 class="text-xl font-extrabold text-on-surface">2. Какие данные обрабатываются</h2>
          <p class="mt-3">Имя, телефон, email, данные учетной записи, сведения о заказах, курсах и прогрессе, а также технические данные, необходимые для работы Сервиса: IP-адрес, сведения об устройстве и браузере, cookie, дата и время действий.</p>
          <p class="mt-3">Сведения о здоровье и иные специальные категории персональных данных обрабатываются только при наличии предусмотренного законом основания, включая отдельное согласие пользователя, когда оно требуется.</p>
        </section>

        <section>
          <h2 class="text-xl font-extrabold text-on-surface">3. Цели обработки</h2>
          <p class="mt-3">Регистрация и обслуживание учетной записи, предоставление доступа к материалам и программам, ведение клиентского прогресса, обработка заказов и оплаты, ответы на обращения, сервисные уведомления, обеспечение безопасности и улучшение Сервиса.</p>
        </section>

        <section>
          <h2 class="text-xl font-extrabold text-on-surface">4. Передача и хранение данных</h2>
          <p class="mt-3">Оператор передает минимально необходимые данные платежным и техническим провайдерам, а также привлеченным специалистам исключительно для оказания услуг и работы Сервиса. Данные хранятся не дольше, чем необходимо для целей обработки, исполнения договора и соблюдения требований законодательства.</p>
        </section>

        <section>
          <h2 class="text-xl font-extrabold text-on-surface">5. Права пользователя</h2>
          <p class="mt-3">Пользователь вправе запросить сведения об обработке данных, их уточнение, блокирование или удаление при наличии оснований, а также отозвать согласие. Для этого направьте обращение на <a :href="`mailto:${operatorEmail}`" class="font-semibold text-primary underline underline-offset-4">{{ operatorEmail }}</a>.</p>
        </section>

        <section>
          <h2 class="text-xl font-extrabold text-on-surface">6. Cookie</h2>
          <p class="mt-3">Сервис использует cookie и аналогичные технологии для авторизации, сохранения настроек, безопасности и анализа использования. Ограничение cookie в браузере может повлиять на работу отдельных функций.</p>
        </section>
      </div>

      <p class="mt-10 border-t border-white/10 pt-6 text-sm text-on-muted">Полная редакция документа доступна в разделе документации проекта. Актуальные реквизиты Оператора публикуются здесь после их заполнения.</p>
    </article>

    <footer class="mx-auto max-w-4xl pb-6 text-center text-sm text-on-muted">
      <RouterLink to="/" class="underline decoration-primary/60 underline-offset-4 transition hover:text-primary">Вернуться на главную</RouterLink>
    </footer>
  </main>
</template>
