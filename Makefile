.DEFAULT_GOAL := help

COMPOSER ?= composer
PHP_VERSION ?= 8.5

# PHP 8.6 RC (and later RCs): Docker Hub tag `8.6-rc`; Excimer 1.2.7 does not build there yet
# (XtOffsetOf removed in PHP 8.6) — use PIE `dev-master`. Composer platform php is 8.6.0RCx,
# which does not satisfy `~8.6.0` until GA, so ignore the php platform req for RC stands only.
ifneq (,$(findstring -rc,$(PHP_VERSION)))
EXCIMER_PIE_CONSTRAINT ?= dev-master
COMPOSER_IGNORE_PLATFORM_REQ ?= php
else
EXCIMER_PIE_CONSTRAINT ?= 1.2.7
COMPOSER_IGNORE_PLATFORM_REQ ?=
endif

# The stand's PHP container runs as the host user so that files it writes stay yours.
STAND_ENV = PHP_VERSION=$(PHP_VERSION) EXCIMER_PIE_CONSTRAINT=$(EXCIMER_PIE_CONSTRAINT) COMPOSER_IGNORE_PLATFORM_REQ=$(COMPOSER_IGNORE_PLATFORM_REQ) STAND_UID=$$(id -u) STAND_GID=$$(id -g)
STAND_RUN = $(STAND_ENV) docker compose run --rm php

.PHONY: help install lint cs-fix stan rector rector-fix deps test test-unit test-integration coverage infection audit ci \
	stand-build stand-up stand-down stand-smoke stand-shell stand-install stand-test stand-ci \
	app-build app-up app-smoke app-profiles alpine-php-build

help: ## Show available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-18s %s\n", $$1, $$2}'

install: ## Install dependencies
	$(COMPOSER) install

lint: ## Check composer.json and coding style
	$(COMPOSER) validate --strict
	$(COMPOSER) normalize:check
	$(COMPOSER) cs:check

cs-fix: ## Fix coding style and normalize composer.json
	$(COMPOSER) normalize
	$(COMPOSER) cs:fix

stan: ## Run PHPStan (level max)
	$(COMPOSER) stan

rector: ## Check for pending Rector refactorings
	$(COMPOSER) rector:check

rector-fix: ## Apply Rector refactorings
	$(COMPOSER) rector:fix

deps: ## Detect unused and undeclared Composer dependencies
	$(COMPOSER) deps

test: ## Run all test suites
	$(COMPOSER) test

test-unit: ## Run the unit test suite
	$(COMPOSER) test:unit

test-integration: ## Run the integration test suite (needs ext-excimer)
	$(COMPOSER) test:integration

coverage: ## Run all test suites with coverage reports in build/coverage (needs pcov or Xdebug)
	$(COMPOSER) test:coverage

infection: ## Run mutation testing with Infection (needs pcov or Xdebug and the Infection PHAR)
	$(COMPOSER) infection

audit: ## Check dependencies for known vulnerabilities
	$(COMPOSER) audit

ci: ## Run every check CI runs on a pull request
	$(COMPOSER) ci
	$(COMPOSER) audit

stand-build: ## Build the stand's PHP image (PHP_VERSION=8.5; try 8.6-rc for the next PHP RC)
	$(STAND_ENV) docker compose build php

stand-up: ## Start Pyroscope, Grafana, the OTel Collector and Toxiproxy
	docker compose up -d --wait
	docker/wait-for-url.sh http://127.0.0.1:4040/ready

stand-down: ## Stop the stand and remove its volumes
	docker compose --profile php --profile app --profile setup down --volumes --remove-orphans

stand-smoke: ## Check that a running stand is wired correctly
	PHP_VERSION=$(PHP_VERSION) docker/smoke-test.sh

stand-shell: ## Open a shell in the stand's PHP container
	$(STAND_RUN) bash

stand-install: ## Install Composer dependencies inside the stand
	$(STAND_RUN) composer install

stand-test: stand-install ## Run all test suites inside the stand, with ext-excimer
	$(STAND_RUN) composer test

stand-ci: stand-install ## Run every CI check inside the stand, with ext-excimer
	$(STAND_RUN) make ci

app-build: ## Build the reference app images (Symfony Demo on PHP-FPM, nginx and RoadRunner)
	docker compose --profile app --profile setup build

app-up: stand-up app-build ## Start the reference app with PostgreSQL and load its fixtures
	docker compose --profile app up -d --wait postgres httpbin
	docker compose --profile app --profile setup run --rm app-setup
	docker compose --profile app up -d --wait

app-smoke: ## Check that the reference app answers on PHP-FPM and RoadRunner
	docker/reference-app/smoke-test.sh

app-profiles: ## Check profiles in Pyroscope: stacks, wall/ns units, routes, no raw URLs
	docker/reference-app/check-profiles.sh

alpine-php-build: ## Proof-build PHP+excimer on Alpine via PIE (not the default stand image)
	docker build -f docker/php/Dockerfile.alpine \
		--build-arg PHP_VERSION=$(PHP_VERSION) \
		--build-arg EXCIMER_PIE_CONSTRAINT=$(EXCIMER_PIE_CONSTRAINT) \
		-t tracewick-php-alpine:local \
		docker/php
	docker run --rm tracewick-php-alpine:local php -r 'exit(extension_loaded("excimer") ? 0 : 1);'
