# syntax=docker/dockerfile:1

# Reference application for the development stand: the Symfony Demo application, served by
# PHP-FPM behind nginx and by RoadRunner, with ext-excimer installed so that Tracewick can profile
# it. Build arguments pin every version so that a rebuild is reproducible.
#
# Tracewick itself is installed from this repository through the "tracewick" build context
# (compose.yaml points it at the repository root), so the app always runs the code under review.
#
# Targets: fpm (PHP-FPM), nginx (static files and FastCGI proxy), roadrunner (long-running workers).

ARG PHP_VERSION=8.5
ARG ROADRUNNER_VERSION=2025.1.15
ARG NGINX_VERSION=1.30.6

FROM composer:2 AS composer
FROM ghcr.io/roadrunner-server/roadrunner:${ROADRUNNER_VERSION} AS roadrunner-binary

FROM php:${PHP_VERSION}-fpm-bookworm AS app

ARG EXCIMER_VERSION=1.2.6
ARG SYMFONY_DEMO_VERSION=3.1.0
ARG RUNTIME_ROADRUNNER_VERSION=1.0.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" intl opcache pcntl pdo_pgsql sockets \
    && pecl install "excimer-${EXCIMER_VERSION}" \
    && docker-php-ext-enable excimer \
    && rm -rf /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Dev dependencies stay installed: the setup step loads the demo's Doctrine fixtures.
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer create-project --no-interaction --no-progress \
        "symfony/symfony-demo:${SYMFONY_DEMO_VERSION}" /srv/app \
    && cd /srv/app \
    && composer require --no-interaction --no-progress \
        "runtime/roadrunner-symfony-nyholm:${RUNTIME_ROADRUNNER_VERSION}" \
    && composer clear-cache

# Installed after the demo so that a change to Tracewick does not download the demo again.
COPY --from=tracewick composer.json LICENSE NOTICE /opt/tracewick/
COPY --from=tracewick bin/ /opt/tracewick/bin/
COPY --from=tracewick src/ /opt/tracewick/src/
RUN cd /srv/app \
    && composer config repositories.tracewick \
        '{"type": "path", "url": "/opt/tracewick", "options": {"symlink": false}}' \
    && composer require --no-interaction --no-progress "tracewick/tracewick:*@dev" \
    && composer clear-cache \
    && install -d -o www-data -g www-data /var/spool/tracewick

WORKDIR /srv/app

COPY overlay/ /srv/app/
COPY --chmod=755 setup.sh /usr/local/bin/reference-app-setup
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-reference.conf

ENV APP_ENV=prod \
    APP_DEBUG=0
RUN php bin/console importmap:install \
    && php bin/console sass:build \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var

FROM app AS fpm
CMD ["php-fpm"]

FROM app AS roadrunner
COPY --from=roadrunner-binary /usr/bin/rr /usr/local/bin/rr
COPY .rr.yaml /srv/app/.rr.yaml
USER www-data
CMD ["rr", "serve", "-c", "/srv/app/.rr.yaml"]

FROM nginx:${NGINX_VERSION} AS nginx
COPY --from=app /srv/app/public /srv/app/public
COPY nginx.conf /etc/nginx/conf.d/default.conf
