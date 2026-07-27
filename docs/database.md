# Структура базы данных

## Основные таблицы

### users

Пользователи системы.

- id
- name
- email
- password
- role: client | trainer | admin
- phone
- avatar_path
- email_verified_at
- timestamps

### trainer_profiles

- id
- user_id
- bio
- specialization
- experience_years
- instagram_url
- timestamps

### client_profiles

- id
- user_id
- trainer_id
- goal
- height_cm
- birth_date
- activity_level
- medical_notes
- timestamps

### courses

- id
- trainer_id
- title
- slug
- description
- cover_path
- price_cents
- currency
- level
- status: draft | published | archived
- starts_at
- timestamps

### course_modules

- id
- course_id
- title
- sort_order
- timestamps

### lessons

- id
- module_id
- title
- description
- type: video | podcast | text | stream
- video_path
- audio_path
- duration_seconds
- is_preview
- sort_order
- published_at
- timestamps

### purchases

- id
- user_id
- course_id
- status: pending | paid | canceled | refunded
- amount_cents
- currency
- paid_at
- timestamps

### payments

- id
- purchase_id
- provider
- provider_payment_id
- status: pending | succeeded | failed | refunded
- amount_cents
- currency
- metadata jsonb
- timestamps

### lesson_progress

- id
- user_id
- lesson_id
- status: not_started | in_progress | completed
- progress_percent
- completed_at
- timestamps

### nutrition_plans

- id
- client_id
- trainer_id
- title
- starts_on
- ends_on
- notes
- timestamps

### meals

- id
- nutrition_plan_id
- meal_type
- title
- calories
- protein_g
- fat_g
- carbs_g
- eaten_at
- timestamps

### progress_entries

- id
- user_id
- weight_kg
- waist_cm
- hips_cm
- chest_cm
- photo_path
- mood
- comment
- measured_on
- timestamps

### streams

- id
- trainer_id
- course_id nullable
- title
- description
- starts_at
- ends_at
- stream_url
- recording_path
- status: scheduled | live | finished | canceled
- timestamps

### notifications

- id
- user_id
- type
- title
- body
- data jsonb
- read_at
- timestamps

## Индексы и связи

- users.email уникален.
- courses.slug уникален.
- purchases уникален по user_id + course_id.
- progress_entries индекс по user_id + measured_on.
- notifications индекс по user_id + read_at.
- lessons индекс по module_id + sort_order.

