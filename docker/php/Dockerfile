# syntax=docker/dockerfile:1

# PHP image for the development stand: the PHP CLI with ext-excimer (via PIE) and Composer.
# Build arguments pin every version so that a rebuild is reproducible.
#
# Prefer PIE (`pie install wikimedia/excimer`) — see docs/install-excimer.md.
# Official PHP 8.6 RC images no longer ship `pecl`; PIE is required there.
#
# PHP_VERSION may be a stable tag (8.4) or an RC tag (8.6-rc) matching
# `php:${PHP_VERSION}-cli-bookworm` on Docker Hub.

ARG PHP_VERSION=8.4

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-bookworm

# Packagist constraint for wikimedia/excimer. Use `dev-master` on PHP 8.6 RC until a
# release includes the XtOffsetOf → offsetof fix (needed after PHP 8.6 removed XtOffsetOf).
ARG EXCIMER_PIE_CONSTRAINT=1.2.7

COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip libtool libonig-dev \
    # PHP 8.6 RC bookworm images omit mbstring; PHPUnit and our stack need it.
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
