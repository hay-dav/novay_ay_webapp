# Развёртывание в Timeweb Cloud

## Архитектура

- VPS: Nginx, frontend, Laravel PHP-FPM, queue worker, scheduler, PostgreSQL и Redis.
- Timeweb S3: все пользовательские и учебные медиа.
- Timeweb CDN: выдача видео и аудио из приватного S3 по Secure Token URL.
- Снаружи открыты только TCP 80/443 и необходимые порты LiveKit/TURN, если трансляции включены. PostgreSQL и Redis не публикуются.

## 1. Подготовка Timeweb

1. Создайте VPS Ubuntu LTS, добавьте SSH-ключ и включите автоматические бэкапы.
2. В firewall разрешите SSH только с доверенных IP, HTTP 80 и HTTPS 443.
3. Создайте приватный S3-бакет. Сохраните Access Key, Secret Key и имя бакета.
4. Создайте CDN-ресурс с источником «S3-бакет», включите AWS-авторизацию для приватного бакета.
5. В CDN включите HTTPS и Secure Token с проверкой срока действия. Секрет должен совпадать с `CDN_SECURE_TOKEN`.
6. Направьте DNS `app.example.ru` и `api.example.ru` на VPS, а `cdn.example.ru` — CNAME на технический домен CDN.

## 2. Секреты

```bash
cp backend/.env.production.example backend/.env.production
cp .env.production.example .env.production
chmod 600 backend/.env.production .env.production
```

Заполните все пустые значения. Сгенерируйте секреты:

```bash
openssl rand -base64 32
openssl rand -hex 32
```

`APP_KEY` создайте командой `docker compose ... run --rm backend php artisan key:generate --show` и сохраните как `APP_KEY=base64:...`.

У администратора используется `lazareva.secret@yandex.ru`. Для Дины необходимо указать другой email: таблица пользователей и безопасная идентификация не допускают один логин для двух людей.

## 3. TLS и запуск

Установите Docker Engine, Compose plugin и Certbot. До первого запуска получите сертификаты в standalone-режиме:

```bash
sudo certbot certonly --standalone -d app.example.ru
sudo certbot certonly --standalone -d api.example.ru
```

Compose уже монтирует `/etc/letsencrypt` в gateway только для чтения. Для продления standalone-сертификата временно остановите gateway, выполните `sudo certbot renew`, затем запустите gateway снова.

```bash
docker compose --env-file .env.production -f docker-compose.production.yml build --pull
docker compose --env-file .env.production -f docker-compose.production.yml up -d
docker compose --env-file .env.production -f docker-compose.production.yml exec backend php artisan migrate --force
docker compose --env-file .env.production -f docker-compose.production.yml exec backend php artisan db:seed --force
docker compose --env-file .env.production -f docker-compose.production.yml exec backend php artisan optimize
```

Seeder создаёт только двух сотрудников из production-секретов. После первого входа смените оба начальных пароля и удалите `ADMIN_INITIAL_PASSWORD`/`CURATOR_INITIAL_PASSWORD` из env, затем перезапустите контейнеры.

## 4. Проверка

```bash
curl -fsS https://api.example.ru/up
docker compose --env-file .env.production -f docker-compose.production.yml ps
docker compose --env-file .env.production -f docker-compose.production.yml logs --tail=100 backend queue
```

Проверьте загрузку аватара, видео, Range-воспроизведение через CDN, истечение Secure Token URL, очередь и резервное восстановление PostgreSQL. Настройте ежедневный `pg_dump` в отдельный закрытый S3-prefix и регулярно тестируйте восстановление.

## 5. Обновление

```bash
git pull --ff-only
docker compose --env-file .env.production -f docker-compose.production.yml build --pull
docker compose --env-file .env.production -f docker-compose.production.yml run --rm backend php artisan migrate --force
docker compose --env-file .env.production -f docker-compose.production.yml up -d --remove-orphans
```

Не запускайте `migrate:fresh`, `db:wipe` или seed с тестовыми данными в production.
