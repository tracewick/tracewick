# 2. Supported PHP versions

- Status: Accepted
- Date: 2026-10-29

## Context

Tracewick runs inside production applications, so it must install wherever its users run PHP. At
the same time every supported version costs CI minutes, conditional code and blocked language
features. ext-excimer itself supports PHP 7.2 and later, so the extension does not limit us.

In November 2026 PHP 8.2 receives security fixes only (until 31 December 2026), 8.3–8.5 are
supported releases, and PHP 8.6 is in RC (GA planned mid-November 2026).

## Decision

- Tracewick supports every PHP version that receives active or security support from the PHP
  project at the time of a release. Today that is PHP 8.2, 8.3, 8.4 and 8.5.
- `composer.json` lists the supported versions explicitly (`~8.2.0 || ~8.3.0 || ...`), so a new
  PHP version is supported only after CI has run on it.
- Support for a new PHP version is added as soon as its first RC is available in CI and released
  in the next minor version. Until then the docker stand accepts `PHP_VERSION=8.6-rc` and the
  Stand workflow runs an **experimental** (non-blocking) job on that image.
- Dropping a PHP version that reached end of life happens in a minor release, never in a patch
  release, and is announced in `CHANGELOG.md` one release in advance.
- The code targets the oldest supported version; Rector's level set follows it.

## Consequences

- Users on any supported PHP version can install Tracewick; users on end-of-life versions stay on
  an older Tracewick release.
- PHP 8.2 support will be dropped in the first minor release after 31 December 2026, which unlocks
  PHP 8.3 features (typed class constants, `#[\Override]`, `json_validate()`).
- CI must test every supported version; this is why the full matrix runs on `main`, `develop` and
  nightly.
