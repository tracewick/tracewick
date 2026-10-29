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
| `make coverage`         | Run the tests with coverage reports in `build/coverage/`  |
| `make infection`        | Mutation testing with [Infection](https://infection.github.io/) |

Coverage needs [pcov](https://github.com/krakjoe/pcov) or Xdebug. Infection is not a Composer
dependency because recent versions need PHP 8.3 or newer; install its
[PHAR](https://infection.github.io/guide/installation.html) (CI uses the version in
`.github/workflows/ci.yml`).

## Development stand

The stand in [`compose.yaml`](compose.yaml) gives you PHP with ext-excimer and the backends Tracewick
talks to, so you need only Docker with Compose v2 on your machine:

```bash
make stand-up      # start the backends and wait until they are ready
make stand-smoke   # check that the stand is wired correctly
make stand-test    # install dependencies and run all test suites with ext-excimer
make stand-ci      # run everything CI runs on a pull request
make stand-shell   # open a shell in the PHP container
make stand-down    # stop everything and remove volumes
```

Set `PHP_VERSION` (8.2, 8.3, 8.4 or 8.5; 8.4 by default) to use another PHP version, for example
`make stand-test PHP_VERSION=8.2`. Each PHP version gets its own image (`tracewick-php:<version>`),
built on first use; after changing anything under `docker/php/`, rebuild it with
`make stand-build`. The PHP container runs as your user, so files it creates in the working copy
stay yours.

The stand is for developing and testing Tracewick only. Neither `compose.yaml` nor `docker/` ships
in the Composer package. Its PHP image deliberately runs with strict development settings
(`docker/php/conf.d/zz-tracewick.ini`: all errors displayed, assertions on), so that tests fail on
any notice or deprecation; this says nothing about how applications should configure PHP.

To look at profiles, open Grafana at http://127.0.0.1:3000, choose Explore (or Drilldown →
Profiles) and the provisioned Pyroscope data source; or use the Pyroscope UI at
http://127.0.0.1:4040.

| Service        | URL from the host        | URL inside the stand        | Purpose                       |
|----------------|--------------------------|-----------------------------|-------------------------------|
| Pyroscope      | http://127.0.0.1:4040    | `http://pyroscope:4040`     | Profile storage and UI        |
| Grafana        | http://127.0.0.1:3000    | `http://grafana:3000`       | Profiles explorer, no login   |
| OTel Collector | 127.0.0.1:4317 (gRPC), http://127.0.0.1:4318 | `http://otel-collector:4318` | OTLP; forwards profiles to Pyroscope |
| Toxiproxy      | http://127.0.0.1:8474 (API) | `http://toxiproxy:14040` (to Pyroscope), `:14318` (to the Collector) | Latency and failure injection |

The PHP container gets these addresses as `PYROSCOPE_URL`, `PYROSCOPE_TOXIC_URL`,
`OTEL_EXPORTER_OTLP_ENDPOINT` and `TOXIPROXY_URL`. Ports listen on `127.0.0.1` only.

### Toxiproxy and chaos tests

[Toxiproxy](https://github.com/Shopify/toxiproxy) is a TCP proxy that can inject network faults on
demand. In the stand it sits between PHP and the backends: `http://toxiproxy:14040` is Pyroscope
and `http://toxiproxy:14318` is the Collector, but with whatever faults a test switches on through
the API at `TOXIPROXY_URL`. Chaos tests use it to prove the fail-open promise (see
[ADR 5](docs/adr/0005-fail-open-policy.md)): when a backend is slow, drops connections, returns
errors or is down, the host application must not notice.

Tests talk to the real backends through the plain URLs and to the faulty ones through the
`toxiproxy` URLs. A test that adds a toxic must remove it when it ends, for example:

```bash
# Add 10 s of latency to every response from Pyroscope, then remove it.
curl -X POST http://127.0.0.1:8474/proxies/pyroscope/toxics \
     -d '{"name":"slow","type":"latency","stream":"downstream","attributes":{"latency":10000}}'
curl -X DELETE http://127.0.0.1:8474/proxies/pyroscope/toxics/slow
```

Proxies are defined in [`docker/toxiproxy/toxiproxy.json`](docker/toxiproxy/toxiproxy.json).

### Updating the stand

The backend images and the ext-excimer version are pinned, so the stand does not change under
you; Dependabot proposes backend image updates in `compose.yaml` into `develop`. The PHP base image
follows its minor version tag (`php:8.4-cli-bookworm`), so a rebuild picks up PHP patch releases.
ext-excimer (`EXCIMER_VERSION` in `compose.yaml` and `docker/php/Dockerfile`) is updated by hand
when PECL publishes a release. The `Stand` workflow builds and tests the stand on every pull
request that touches it, on PHP 8.4, and on all supported PHP versions after the merge.

## Rules

- **Fail-open.** Library code must never throw into, block, or noticeably slow down the host
  application. Failures are swallowed, counted and reported through diagnostics.
- **Coding style** is [PER-CS 2.0](https://www.php-fig.org/per/coding-style/), enforced by
  PHP-CS-Fixer. Every file declares `strict_types=1`; classes are `final` unless designed for
  extension.
- **Static analysis** runs at PHPStan level `max`. Do not add baseline entries or `@phpstan-ignore`
  without a comment explaining why.
- **Tests** accompany every change. Never skip, disable or delete a test to make CI pass.
  Pull requests must keep line coverage at 90% or more (checked by Codecov) and the mutation
  score indicator (MSI) at 80% or more (checked by Infection).
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
- Do not edit `CHANGELOG.md`: it is generated from commit messages at release time, so write the
  commit subject for the user who reads the changelog. `feat` lands under Added, `fix` under
  Fixed, `perf`, `deps` and `revert` under Changed; other types are left out.
- Pull requests run checks on a single PHP version; the full PHP 8.2–8.5 matrix runs on `main`,
  `develop` and nightly.

## Releasing

Releases follow git flow and are automated with
[release-please](https://github.com/googleapis/release-please):

1. Branch `release/<version>` from `develop`, stabilize it, and merge it into `main` with a merge
   commit (not a squash), so that release-please sees every Conventional Commit.
2. The `Release` workflow opens or updates a `chore: release <version>` pull request into `main`
   with the generated changelog. Review it and merge it.
3. Merging it tags `v<version>` and publishes the GitHub Release; Packagist picks up the tag.
4. The workflow opens a pull request merging `main` back into `develop`. Merge it with a merge
   commit.

Before 1.0.0, `feat` bumps the minor version and `fix` the patch version. A breaking change
(`feat!:` or a `BREAKING CHANGE:` footer) bumps the minor version until 1.0.0 and the major
version after it.

release-please computes the version from the commits since the last release; the release pull
request shows it in its title. To choose another version, add a `Release-As: <version>` footer to a
commit that lands on `main`, for example the merge commit of the release branch.

### Release automation setup (maintainers)

Tags `v*` are protected by a ruleset, so the workflow acts as a dedicated GitHub App that is on the
ruleset's bypass list. Pull requests opened by the App also trigger CI, which pull requests opened
with `GITHUB_TOKEN` do not.

1. Create a GitHub App owned by the `tracewick` organization (Settings → Developer settings →
   GitHub Apps), for example `tracewick-release`: no webhook; repository permissions Contents and
   Pull requests set to Read and write, Metadata Read-only. Install it on `tracewick/tracewick`.
2. In the repository settings, add the App ID as the Actions variable `RELEASE_APP_ID` and a
   generated private key as the Actions secret `RELEASE_APP_PRIVATE_KEY`.
3. Add the App to the bypass list of the tag ruleset (and of branch rulesets on `main` and
   `develop`, if the release pull requests must not wait for required reviews).

Until the variable is set, the workflow uses `GITHUB_TOKEN`, which needs "Allow GitHub Actions to
create and approve pull requests" enabled and cannot bypass rulesets.

## License

By contributing, you agree that your contributions are licensed under the
[Apache License 2.0](LICENSE).
