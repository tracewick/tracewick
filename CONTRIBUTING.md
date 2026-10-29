# Contributing to Tracewick

Thank you for your interest in Tracewick! This guide explains how to propose changes.

## Before you start

- For anything larger than a small fix, open an issue first so we can agree on the approach.
- Architecture decisions are recorded in [`docs/adr/`](docs/adr/). A change that contradicts an
  accepted ADR needs a new ADR.

## Development setup

You need PHP 8.2+ and Composer. Install [ext-excimer](https://pecl.php.net/package/excimer) to run
the integration tests; without it they are skipped.

```bash
composer install
make help   # list available targets
make ci     # run every check CI runs on a pull request
```

| Target                  | What it does                                              |
|-------------------------|-----------------------------------------------------------|
| `make lint`             | `composer validate`, `composer normalize`, PHP-CS-Fixer    |
| `make cs-fix`           | Fix coding style and normalize `composer.json`            |
| `make stan`             | PHPStan at level `max` with strict and deprecation rules  |
| `make rector`           | Check for pending Rector refactorings                     |
| `make deps`             | Detect unused and undeclared Composer dependencies        |
| `make test`             | Run the unit and integration test suites                  |

## Rules

- **Fail-open.** Library code must never throw into, block, or noticeably slow down the host
  application. Failures are swallowed, counted and reported through diagnostics.
- **Coding style** is [PER-CS 2.0](https://www.php-fig.org/per/coding-style/), enforced by
  PHP-CS-Fixer. Every file declares `strict_types=1`; classes are `final` unless designed for
  extension.
- **Static analysis** runs at PHPStan level `max`. Do not add baseline entries or `@phpstan-ignore`
  without a comment explaining why.
- **Tests** accompany every change. Never skip, disable or delete a test to make CI pass.
- **Language.** Code, comments, commit messages, issues and documentation are written in English.

## Branching model

Tracewick follows [git flow](https://nvie.com/posts/a-successful-git-branching-model/):

- `main` holds released code only; every commit on it is a tagged release.
- `develop` is the integration branch. Feature pull requests target `develop`.
- `feature/<short-name>` branches start from `develop` and merge back into it.
- `release/<version>` branches start from `develop`, receive only stabilization fixes, and merge
  into both `main` (tagged) and `develop`.
- `hotfix/<version>` branches start from `main`, fix a released version, and merge into both
  `main` (tagged) and `develop`.

## Commits and pull requests

- Commit messages and PR titles follow [Conventional Commits](https://www.conventionalcommits.org/),
  for example `feat(encoder): add pprof encoder` or `fix(sampler): keep event type on reset`.
- Keep pull requests focused: one logical change per PR.
- Update `CHANGELOG.md` under `Unreleased` when users will notice the change.
- Pull requests run checks on a single PHP version; the full PHP 8.2–8.5 matrix runs on `main`,
  `develop` and nightly.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).
