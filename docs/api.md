# API-контракт

Базовый путь: `/api/v1`.

## Auth

- `POST /auth/register`
- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`

## Catalog

- `GET /courses`
- `GET /courses/{slug}`
- `POST /trainer/courses`
- `PATCH /trainer/courses/{course}`

## Commerce

- `POST /courses/{course}/purchase`
- `GET /purchases`
- `POST /payments/{payment}/confirm`

## Learning

- `GET /my/courses`
- `PATCH /lessons/{lesson}/progress`

## Coaching

- `GET /nutrition-plans/current`
- `POST /progress`
- `GET /progress`

## Trainer

- `GET /trainer/dashboard`
- `GET /trainer/clients`
- `POST /trainer/clients/{client}/nutrition-plans`

## Realtime

- Private channel: `private-users.{id}`
- Trainer channel: `private-trainers.{id}`
- Events: `NotificationCreated`, `LessonCompleted`, `StreamStarted`

