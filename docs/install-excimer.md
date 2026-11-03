# Installing ext-excimer

Tracewick collects samples only when [ext-excimer](https://www.mediawiki.org/wiki/Excimer) is
loaded. Without it the library installs and stays fail-open: profilers become no-ops and emit one
warning.

**PECL is deprecated.** Install extensions with
[PIE (PHP Installer for Extensions)](https://php.github.io/pie/) — the replacement adopted by PHP
([RFC](https://wiki.php.net/rfc/adopt_pie_deprecate_pecl)). Excimer on Packagist:
[`wikimedia/excimer`](https://packagist.org/packages/wikimedia/excimer).

Both install paths are documented below. Prefer PIE on hosts and in Dockerfiles. The Bookworm
development stand installs via PIE (`EXCIMER_PIE_CONSTRAINT` in `compose.yaml` /
`docker/php/Dockerfile`). Official PHP **8.6 RC** images no longer ship `pecl`.

| Path | How to pin today |
| --- | --- |
| PIE (recommended) | `pie install wikimedia/excimer:1.2.7` (Packagist tag) |
| PIE on PHP 8.6 RC | `pie install wikimedia/excimer:dev-master` until a release builds on 8.6 (`XtOffsetOf` removed) |
| PECL (deprecated) | `pecl install excimer-1.2.6` (not available in `php:8.6-rc-*` images) |

## PIE (recommended)

Install [PIE](https://php.github.io/pie/) first (PHAR, same idea as Composer), then:

```bash
# Needs build tools (phpize / a C toolchain). Official php:* images already include them via $PHPIZE_DEPS.
pie install wikimedia/excimer
# Pin a Packagist version when you need a reproducible build:
# pie install wikimedia/excimer:1.2.7
php -m | grep -i excimer
```

On Alpine / Debian hosts without the official Docker PHP helpers, install the matching `php*-dev`
packages (or `$PHPIZE_DEPS` inside `php:*` images) before `pie install`. PIE enables the extension
when it can; otherwise add `extension=excimer.so` to an ini yourself.

### Docker (PIE)

Copy PIE from the official binary image, then install the Packagist package (see also
`docker/php/Dockerfile.alpine`):

```dockerfile
COPY --from=ghcr.io/php/pie:bin /pie /usr/local/bin/pie
RUN pie install wikimedia/excimer:1.2.7
```

You still need unzip or git, and a C toolchain, in the image.

## PECL (deprecated)

PECL still works for now on hosts that ship it. Prefer PIE for new installs.

### Debian / Ubuntu

```bash
sudo apt-get install -y php-dev php-pear build-essential
sudo pecl install excimer-1.2.6
echo 'extension=excimer.so' | sudo tee /etc/php/8.4/mods-available/excimer.ini
sudo phpenmod excimer   # or enable the ini for FPM/CLI by hand
php -m | grep -i excimer
```

Prefer PIE on Debian as well. The Bookworm stand uses PIE — see `docker/php/Dockerfile`
(`EXCIMER_PIE_CONSTRAINT`).

### Alpine Linux (PECL fallback)

Alpine has no prebuilt `php-excimer` package. With PECL:

```bash
# On the host or in php:8.4-cli-alpine / php:8.4-fpm-alpine
apk add --no-cache $PHPIZE_DEPS
pecl install excimer-1.2.6
docker-php-ext-enable excimer   # official php:* images only
# Host install without docker-php helpers:
#   echo 'extension=excimer.so' > /etc/php84/conf.d/excimer.ini
apk del $PHPIZE_DEPS            # optional: drop compilers after install
php -m | grep -i excimer
```

Prefer `pie install wikimedia/excimer` on Alpine as well (section above). The in-repo Alpine proof
Dockerfile builds with PIE; PECL remains a documented fallback.

### Docker proof recipe in this repository

```bash
docker build -f docker/php/Dockerfile.alpine \
  --build-arg PHP_VERSION=8.4 \
  --build-arg EXCIMER_PIE_CONSTRAINT=1.2.7 \
  -t tracewick-php-alpine:local \
  docker/php

# or: make alpine-php-build
docker run --rm tracewick-php-alpine:local php -m | grep -i excimer
```

This image is **not** published to Docker Hub. Copy the `RUN` / `COPY --from=…pie` block into your
application image, or use it only to verify that Excimer still builds on Alpine when you bump the
PIE constraint (the Bookworm stand uses the same Packagist pin via `EXCIMER_PIE_CONSTRAINT`).

## After install

```bash
composer require tracewick/tracewick
```

If Excimer is missing at runtime, Tracewick still loads; see
[ADR 5](adr/0005-fail-open-policy.md) and [runtime.md](runtime.md).
