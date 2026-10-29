#!/bin/sh
# Creates the schema and loads the Symfony Demo fixtures into PostgreSQL. Safe to run again:
# fixtures:load purges the tables first. Runs in the dev environment, where the fixtures bundle is
# registered.
set -eu

export APP_ENV=dev APP_DEBUG=0
cd /srv/app
php bin/console doctrine:schema:update --force --no-interaction
php bin/console doctrine:fixtures:load --no-interaction
echo 'Reference app database is ready'
