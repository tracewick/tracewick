# syntax=docker/dockerfile:1

# PHP image for the development stand: the PHP CLI with ext-excimer and Composer.
# Build arguments pin every version so that a rebuild is reproducible.

ARG PHP_VERSION=8.4

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-bookworm

ARG EXCIMER_VERSION=1.2.7

RUN apt-get update \
    && apt-get install -y --no-install-recommends git make unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install "excimer-${EXCIMER_VERSION}" \
    && docker-php-ext-enable excimer \
    && rm -rf /tmp/pear \
    && php -r 'exit(extension_loaded("excimer") ? 0 : 1);'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY conf.d/ /usr/local/etc/php/conf.d/

# The stand runs as the host user, so the Composer cache must be writable by anyone.
ENV COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_HOME=/tmp/composer-home
RUN mkdir -p "$COMPOSER_CACHE_DIR" "$COMPOSER_HOME" \
    && chmod 1777 "$COMPOSER_CACHE_DIR" "$COMPOSER_HOME"

WORKDIR /app
