#!/bin/sh

set -eu

mkdir -p \
    storage/app/private/workouts/videos \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache \
    public/uploads/avatars

chown -R www-data:www-data storage bootstrap/cache public/uploads

exec "$@"
