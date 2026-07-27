# Новая Я

Полноценное веб-приложение для нутрициолога и фитнес-тренера: курсы, защищённые видео, питание, прогресс, чат, уведомления, оплаты и кабинеты команды.

## Стек

- Vue 3, Composition API, Vite, Vue Router, Pinia, Axios, Chart.js.
- Laravel 12, Sanctum, PostgreSQL, Redis, очереди и realtime.
- S3-совместимое хранилище; в production — Timeweb Cloud S3 и CDN.
- Docker Compose, Nginx, LiveKit/Coturn для трансляций.

## Локальный запуск

```bash
cp frontend/.env.example frontend/.env
cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
```

Seed по умолчанию пуст: тестовые пользователи, уроки и отчёты в проекте не создаются.

Production-развёртывание описано в [docs/deployment-timeweb.md](docs/deployment-timeweb.md). Результаты аудита находятся в [docs/code-review.md](docs/code-review.md).
