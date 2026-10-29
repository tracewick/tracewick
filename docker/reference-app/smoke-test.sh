#!/bin/sh
# Checks that the reference app answers on PHP-FPM and on RoadRunner. Run it after `make app-up`.
set -eu

cd "$(dirname "$0")/../.."

for base in http://127.0.0.1:8081 http://127.0.0.1:8082; do
    echo "--> $base serves the Symfony Demo blog"
    docker/wait-for-url.sh "$base/en/blog/" 120

    echo "--> $base reaches PostgreSQL and the external HTTP service through Toxiproxy"
    response=$(curl -fsS "$base/reference/external")
    echo "$response"
    echo "$response" | grep -q '"upstream_status":200'
    echo "$response" | grep -Eq '"posts":[1-9]'
done

echo 'Reference app smoke test passed'
