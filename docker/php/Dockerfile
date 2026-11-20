# syntax=docker/dockerfile:1

# PHP image for the development stand: the PHP CLI with ext-excimer (via PIE) and Composer.
# Excimer is pinned; PHP and tooling tags follow their upstream updates.
#
# Prefer PIE (`pie install wikimedia/excimer`) — see docs/install-excimer.md.
# Official PHP 8.6 images no longer ship `pecl`.
#
# PHP_VERSION selects a stable tag (for example 8.5 or 8.6.0) matching
# `php:${PHP_VERSION}-cli-bookworm` on Docker Hub.

ARG PHP_VERSION=8.5

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-bookworm

# Packagist constraint for wikimedia/excimer. Make selects the pending 1.2.8
# release for PHP 8.6; missing releases must fail the build without a dev fallback.
ARG EXCIMER_PIE_CONSTRAINT=1.2.7

COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip libtool libonig-dev \
    # PHP 8.6 Bookworm images omit mbstring; PHPUnit and our stack need it.
    && docker-php-ext-install -j"$(nproc)" mbstring \
    && pie install "wikimedia/excimer:${EXCIMER_PIE_CONSTRAINT}" --auto-install-build-tools \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
    && apt-get install -y --no-install-recommends git make unzip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear /tmp/pie_* \
    && php -r 'exit(extension_loaded("excimer") && extension_loaded("mbstring") ? 0 : 1);'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY conf.d/ /usr/local/etc/php/conf.d/

# The stand runs as the host user, so the Composer cache must be writable by anyone.
ENV COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_HOME=/tmp/composer-home
RUN mkdir -p "$COMPOSER_CACHE_DIR" "$COMPOSER_HOME" \
    && chmod 1777 "$COMPOSER_CACHE_DIR" "$COMPOSER_HOME"

WORKDIR /app
