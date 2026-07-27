FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    bash \
    ffmpeg \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    jpegoptim \
    optipng \
    postgresql-dev \
    zip \
    unzip \
    && docker-php-ext-install intl mbstring pdo_pgsql zip bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/app-entrypoint"]

CMD ["php-fpm"]
