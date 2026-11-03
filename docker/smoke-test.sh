#!/bin/sh
# Checks that a running stand is wired correctly. Run it after `make stand-up`.
set -eu

cd "$(dirname "$0")/.."

echo '--> Pyroscope is ready'
docker/wait-for-url.sh http://127.0.0.1:4040/ready

echo '--> OTel Collector is healthy'
docker/wait-for-url.sh http://127.0.0.1:13133/

echo '--> Grafana reaches Pyroscope through the provisioned data source'
curl -fsS http://127.0.0.1:3000/api/datasources/uid/pyroscope/health
echo

echo '--> Toxiproxy exposes the configured proxies'
curl -fsS http://127.0.0.1:8474/proxies | grep -q '"pyroscope"'

echo '--> The PHP container has ext-excimer and reaches Pyroscope through Toxiproxy'
PHP_VERSION=${PHP_VERSION:-8.5} STAND_UID=$(id -u) STAND_GID=$(id -g) \
    docker compose run --rm --no-deps php sh -c \
    'php --ri excimer && curl -fsS -o /dev/null "$PYROSCOPE_TOXIC_URL/ready"'

echo 'Stand smoke test passed'
