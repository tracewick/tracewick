# 2. Supported PHP versions

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-20

## Context

Tracewick runs inside production applications, so it must install wherever its users run PHP. At
the same time every supported version costs CI minutes, conditional code and blocked language
features. Native-extension compatibility must also be checked; a stable PHP release alone does
not establish that the latest released Excimer builds on it.

In November 2026 PHP 8.2 receives security fixes only (until 31 December 2026), 8.3–8.5 are
supported releases, and PHP 8.6.0 became stable on 19 November 2026. Excimer 1.2.7 does not build
on 8.6; its upstream compatibility fix is still unreleased. See the exact
[source pin and installation caveat](../install-excimer.md#php-86).

## Decision

- Tracewick supports every PHP version that receives active or security support from the PHP
  project at the time of a release. Today that is PHP 8.2, 8.3, 8.4, 8.5 and 8.6.
- `composer.json` lists the supported versions explicitly (`~8.2.0 || ~8.3.0 || ...`), so a new
  PHP version is supported only after CI has run on it.
- Support for a new PHP version is added as soon as its first RC is available in CI and released
  in the next minor version. The PHP 8.6 stand now uses the stable image in blocking CI with
  the pinned upstream Excimer fix; it no longer uses an RC image or ignores Composer's PHP
  requirement. PHP 8.5 remains the default local stand with released Excimer 1.2.7.
- Dropping a PHP version that reached end of life happens in a minor release, never in a patch
  release, and is announced in `CHANGELOG.md` one release in advance.
- The code targets the oldest supported version; Rector's level set follows it.

## Consequences

- Users on any supported PHP version can install Tracewick; users on end-of-life versions stay on
  an older Tracewick release.
- PHP 8.2 support will be dropped in the first minor release after 31 December 2026, which unlocks
  PHP 8.3 features (typed class constants, `#[\Override]`, `json_validate()`).
- CI must test every supported version; the lowest/highest dependency matrix runs on pull
  requests, `main`, `develop` and nightly. The stand also covers real backends on relevant PRs
  and every supported PHP version after merge.
