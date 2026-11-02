# Installing ext-excimer

Tracewick collects samples only when [ext-excimer](https://pecl.php.net/package/excimer) is loaded.
Without it the library installs and stays fail-open: profilers become no-ops and emit one warning.

Pin the same PECL version the development stand uses (`EXCIMER_VERSION`, currently **1.2.6** in
`compose.yaml` and `docker/php/Dockerfile`).

## Debian / Ubuntu (and the Bookworm stand)

```bash
sudo apt-get install -y php-dev php-pear build-essential
sudo pecl install excimer-1.2.6
echo 'extension=excimer.so' | sudo tee /etc/php/8.4/mods-available/excimer.ini
sudo phpenmod excimer   # or enable the ini for FPM/CLI by hand
php -m | grep -i excimer
```

Official images already wrap this as `docker-php-ext-enable` — see `docker/php/Dockerfile`.

## Alpine Linux

Alpine has no prebuilt `php-excimer` package. Build from PECL with the PHP development toolchain:

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

`$PHPIZE_DEPS` is defined in official `php:*alpine` images. On a bare Alpine PHP package, install
`autoconf`, `g++`, `make`, and the matching `php84-dev` (or your PHP minor) instead.

### Docker proof recipe in this repository

```bash
docker build -f docker/php/Dockerfile.alpine \
  --build-arg PHP_VERSION=8.4 \
  --build-arg EXCIMER_VERSION=1.2.6 \
  -t tracewick-php-alpine:local \
  docker/php

docker run --rm tracewick-php-alpine:local php -m | grep -i excimer
```

This image is **not** published to Docker Hub. Copy the `RUN` block into your application image, or
use it only to verify that PECL still builds on Alpine when you bump `EXCIMER_VERSION`.

## After install

```bash
composer require tracewick/tracewick
```

If Excimer is missing at runtime, Tracewick still loads; see
[ADR 5](adr/0005-fail-open-policy.md) and [runtime.md](runtime.md).
