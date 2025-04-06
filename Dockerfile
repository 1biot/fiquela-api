# BASE PHP LAYER
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    unzip \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    && docker-php-ext-install intl zip pdo pdo_mysql opcache mbstring

WORKDIR /app

# COMPOSER FROM OFFICIAL IMAGE
FROM composer:latest AS composer

# BUILDER
FROM base AS builder

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY . /app

RUN composer install --no-dev --optimize-autoloader

# FINAL IMAGE
FROM base

COPY --from=builder /app /app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

RUN mkdir -p /app/workspace && \
    mkdir -p /app/temp && \
    chown -R www-data:www-data /app/workspace /app/temp && \
    chmod -R 775 /app/workspace /app/temp

ARG API_TOKEN
ENV API_TOKEN=${API_TOKEN}

CMD ["php-fpm"]
