# BASE PHP LAYER
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    unzip \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    reetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install intl zip pdo pdo_mysql opcache mbstring gd

COPY config/docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /app

# COMPOSER FROM OFFICIAL IMAGE
FROM composer:latest AS composer

# BUILDER
FROM base AS builder

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY . /app

RUN composer install --no-dev --optimize-autoloader

# FINAL IMAGE
FROM base as final

COPY --from=builder /app /app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

VOLUME /app/workspace

RUN mkdir -p /app/temp && \
    mkdir -p /app/logs && \
    chown -R www-data:www-data /app/temp /app/logs && \
    chmod -R 775 /app/temp /app/logs

RUN chmod +x /app/entrypoint.sh
ENTRYPOINT ["/app/entrypoint.sh"]
