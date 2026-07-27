# Переменные окружения

Файлы с переменными окружения и секретами намеренно не хранятся в Git. Создайте их непосредственно на VPS с правами `600`:

```bash
touch .env.production backend/.env.production frontend/.env.production
chmod 600 .env.production backend/.env.production frontend/.env.production
```

Перед первым запуском заполните следующие значения в защищённом хранилище секретов или соответствующих файлах на сервере.

## `.env.production`

`POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `VITE_API_URL`, `VITE_REVERB_*`, `VITE_LIVEKIT_URL`, `VITE_LIVEKIT_WS_URL`, `SOKETI_*`, `LIVEKIT_*`, `TURN_*`, `APP_DOMAIN`, `API_DOMAIN`.

## `backend/.env.production`

`APP_NAME`, `APP_ENV`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `APP_KEY`, `APP_KEY_PREVIOUS`, `CONTACT_HASH_KEY`, `DB_*`, `REDIS_*`, `SESSION_*`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `QUEUE_CONNECTION`, `BROADCAST_CONNECTION`, `PUSHER_*`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION`, `CDN_URL`, `CDN_SECURE_TOKEN`, `ADMIN_EMAIL`, `ADMIN_INITIAL_PASSWORD`, `CURATOR_EMAIL`, `CURATOR_INITIAL_PASSWORD`, `LIVEKIT_*`, `TURN_*`, `MAIL_*`.

`APP_KEY`, `CONTACT_HASH_KEY`, пароли базы данных, Redis, S3, CDN, почты и стартовых учётных записей должны быть уникальными случайными значениями. Не передавайте эти файлы через Git, мессенджеры или email.

## `frontend/.env.production`

`VITE_API_URL`, `VITE_WS_HOST`, `VITE_WS_PORT`, `VITE_WS_SCHEME`, `VITE_REVERB_APP_KEY`, `VITE_LIVEKIT_URL`, `VITE_LIVEKIT_WS_URL`.

Значения с префиксом `VITE_` становятся частью клиентской сборки, поэтому в них нельзя помещать пароли, API-ключи или иные секреты.
