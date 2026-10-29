.DEFAULT_GOAL := help

COMPOSER ?= composer

.PHONY: help install lint cs-fix stan rector rector-fix deps test test-unit test-integration audit ci

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

audit: ## Check dependencies for known vulnerabilities
	$(COMPOSER) audit

ci: ## Run every check CI runs on a pull request
	$(COMPOSER) ci
	$(COMPOSER) audit
