# syntax=docker/dockerfile:1
#
# Una sola imagen para los 4 servicios de dev (setup, api, reverb, queue) —
# lo que cambia entre ellos es el `command:` en docker-compose.yml, no la
# imagen. El código fuente se monta como bind mount (ver compose), así que
# esta imagen NO copia la app: solo instala PHP + las extensiones que usa
# (Postgres, Redis, GD para las imágenes de preguntas) y Composer.

FROM composer:2 AS composer

FROM php:8.4-cli-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev \
        unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip intl bcmath pcntl sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
